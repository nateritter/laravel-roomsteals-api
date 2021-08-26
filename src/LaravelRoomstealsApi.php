<?php

namespace NateRitter\LaravelRoomstealsApi;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class LaravelRoomstealsApi
{
    /**
     * TODO:
     * - Break into different included classes: Members, Locations, Hotels, Deals, Portals, etc.
     * - Add Location::get(), a method to get a normalized location id and name from a string.
     * - Document the params that need to be sent through for each method to work properly.
     * - Check for old dates or invalid data before taking the time to hit the server.
     */

    /**
     * Guzzle HTTP client
     */
    protected $client;

    /**
     * Member API endpoint
     * @var string
     */
    protected $member_uri = 'https://api.travsrv.com/MemberAPI.aspx';

    /**
     * Deals API
     * @var string
     */
    protected $deals_uri = 'https://api.travsrv.com/Content.aspx';

    /**
     * Hotels API
     * @var string
     */
    protected $hotel_uri = 'https://api.travsrv.com/hotel.aspx';

    /**
     * RoomSteals portal
     * @var string
     */
    protected $portal_uri = 'https://hotels.roomsteals.com/v6';

    /**
     * Admin token
     */
    public $admin_token;

    /**
     * Member token
     */
    public $member_token;

    /**
     * The query we're sending to the API
     * @var array
     */
    public $query = [];

    /**
     * Stack of requests and responses
     * @var array
     */
    public $stack = [];

    /**
     * Construtor
     */
    public function __construct()
    {
        if (! $this->apiCredentialsExist()) {
            throw new \Exception('RoomSteals API credentials do not exist in .env file');
        }
        $this->client = new Client(['http_errors' => false, 'headers' => ['Accept-version' => config('laravelroomstealsapi.roomsteals_api_version')]]);

        $this->getAdminToken();
    }

    /**
     * Checks if the API credentials are in the .env file
     * @return boolean
     */
    private function apiCredentialsExist(): bool
    {
        if (empty(config('laravelroomstealsapi.roomsteals_api_username'))
            || empty(config('laravelroomstealsapi.roomsteals_api_password'))
            || empty(config('laravelroomstealsapi.roomsteals_api_site_admin_username'))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Checks if there are any IDs to ban.
     *
     * @return  boolean
     */
    private function bannedIpsExist(): bool
    {
        if (empty(config('laravelroomstealsapi.roomsteals_banned_ids'))) {
            return false;
        }

        return true;
    }

    /**
     * Gets the banned IDs as an array
     *
     * @return  array
     */
    private function getBannedIds(): array
    {
        return config('laravelroomstealsapi.roomsteals_banned_ids');
    }

    /**
     * Get the current member's SSO URL to the portal
     * @return string
     */
    public function getPortalUri(): string
    {
        return $this->constructPortalUri();
    }

    /**
     * Construct and return the current member's SSO URL to the portal
     * @return string
     */
    public function constructPortalUri(): string
    {
        return $this->portal_uri . '?memberToken=' . urlencode($this->member_token);
    }

    /**
     * Construct and upsert a member
     * @param  array  $params The params to upsert the member with.
     * @return [type]         [description]
     */
    public function constructAndUpsertMember(array $params = [])
    {
        $memberData = $this->constructMemberObject($params);

        // FIXME: This bit is required since ARN has a bug in it where
        // AdditionalInfo isn't saved when created, only when updated
        $decoded = json_decode($memberData);
        if (! isset($decoded->AdditionalInfo) || empty($decoded->AdditionalInfo)) {
            $memberData = $this->constructMemberObject($params);
        }

        return $this->upsertMember(['memberData' => $memberData]);
    }

    /**
     * Delete/Deactive a member
     * @param  array  $params The params used to delete the member.
     * @return [type]         [description]
     */
    public function deleteMember(array $params = [])
    {
        $params['is_active'] = false;
        $memberData = $this->constructMemberObject($params);

        return $this->upsertMember(['memberData' => $memberData]);
    }

    /**
     * Create a memberData object and then json_encode it
     * @param  array  $params
     * @return string
     */
    public function constructMemberObject(array $params = []): string
    {
        $full_name = $params['first_name'] ?? '';
        $full_name .= ' ' . $params['last_name'] ?? '';

        $user = new \stdClass();
        $user->ReferralId = $params['id'] ?? '';
        $user->FirstName = $params['first_name'] ?? '';
        $user->LastName = $params['last_name'] ?? '';
        $user->Email = $params['email'] ?? '';

        // Delete/Deactive or Reactivate a member if 'is_active' passed in (bool)
        if (isset($params['is_active'])) {
            if (empty($params['is_active'])) {
                $user->DeleteMember = true;
            } else {
                $user->ReactivateMember = true;
            }
        }

        $memberData = new \stdClass();
        $memberData->Names = [$user];

        if (isset($params['points'])) {
            $memberData->Points = $params['points'];
        }

        $additionalInfoData = new \stdClass();
        $additionalInfoData->partner = $params['partner'] ?? '';
        $additionalInfoData->id = $params['id'] ?? '';
        $additionalInfoData->name = $full_name;
        $additionalInfoData->email = $params['email'] ?? '';
        $memberData->AdditionalInfo = json_encode($additionalInfoData);

        return json_encode($memberData);
    }

    /**
     * Gets an Admin Token
     * @param  array  $params
     * @return array
     */
    public function getAdminToken(array $params = [])
    {
        $this->query = $this->mergeSiteAdminCredentials($params);

        $response = $this->client->request('GET', $this->member_uri, ['query' => $this->query]);

        $json = json_decode((string) $response->getBody(), true);

        if (isset($json['CurrentToken'])) {
            $this->admin_token = urldecode($json['CurrentToken']);
        }

        $this->stack[] = [
            'function' => __FUNCTION__,
            'params' => $params,
            'code' => $response->getStatusCode(),
            'body' => $json,
            'response' => $response,
        ];

        Log::info('RoomSteals API: ' . __FUNCTION__, $this->stack);

        return end($this->stack);
    }

    /**
     * Creates a Member
     * @param  array  $params
     * @return array
     */
    public function createMember(array $params = [])
    {
        extract($this->upsertMember($params), __FUNCTION__);

        return end($this->stack);
    }

    /**
     * Updates a Member
     * @param  array  $params
     * @return array
     */
    public function updateMember(array $params = [])
    {
        extract($this->upsertMember($params), __FUNCTION__);

        return end($this->stack);
    }

    /**
     * Update or insert/create the member data
     * @param  array  $params
     * @param  mixed  $function Name of function calling this one, or null by default
     * @return array
     */
    private function upsertMember(array $params = [], $function = null)
    {
        $this->query = $this->mergeSiteAdminToken($params);

        $response = $this->client->request('POST', $this->member_uri, [
            'form_params' => $this->query,
        ]);

        $json = json_decode((string) $response->getBody(), true);

        if (isset($json['CurrentToken'])) {
            $this->member_token = urldecode($json['CurrentToken']);
        }

        $this->stack[] = [
            'function' => (! empty($function)) ? $function : __FUNCTION__,
            'params' => $params,
            'code' => $response->getStatusCode(),
            'body' => $json,
            'response' => $response,
        ];

        Log::info('RoomSteals API: ' . __FUNCTION__, $this->stack);

        return end($this->stack);
    }

    /**
     * Merges the site admin credentials into the request
     * @param  array  $query
     * @return array
     */
    private function mergeSiteAdminCredentials(array $query = [], $withToken = true)
    {
        $credentials = [
            'username' => config('laravelroomstealsapi.roomsteals_api_username'),
            'password' => config('laravelroomstealsapi.roomsteals_api_password'),
            'siteid' => config('laravelroomstealsapi.roomsteals_api_site_id'),
        ];

        if ($withToken) {
            $credentials['token'] = 'ARNUSER-' . config('laravelroomstealsapi.roomsteals_api_site_admin_username');
        }

        return array_merge($query, $credentials);
    }

    /**
     * Merges the site admin token into the request
     * @param  array  $query
     * @return array
     */
    private function mergeSiteAdminToken(array $query = [])
    {
        $credentials = [
            'token' => $this->admin_token,
            'siteid' => config('laravelroomstealsapi.roomsteals_api_site_id'),
        ];

        return array_merge($query, $credentials);
    }

    /**
     * Get the locations with the best deals
     * @return array
     */
    public function getDealsLocations(array $params = [])
    {
        $params['type'] = 'findfeaturedlocationdeals';

        $response = $this->client->request('GET', $this->deals_uri, [
            'query' => $params,
        ]);

        $json = json_decode((string) $response->getBody(), true);

        $this->stack[] = [
            'function' => (! empty($function)) ? $function : __FUNCTION__,
            'params' => $params,
            'code' => $response->getStatusCode(),
            'body' => $json,
            'response' => $response,
        ];

        Log::info('RoomSteals API: ' . __FUNCTION__, $this->stack);

        return end($this->stack);
    }

    /**
     * Get the locations with the best deals
     * @return array
     */
    public function getDealsHotels(array $params = [])
    {
        $params['type'] = 'findfeaturedlocationdeals';

        $response = $this->client->request('GET', $this->deals_uri, [
            'query' => $params,
        ]);

        $json = json_decode((string) $response->getBody(), true);

        $this->stack[] = [
            'function' => (! empty($function)) ? $function : __FUNCTION__,
            'params' => $params,
            'code' => $response->getStatusCode(),
            'body' => $json,
            'response' => $response,
        ];

        Log::info('RoomSteals API: ' . __FUNCTION__, $this->stack);

        return end($this->stack);
    }

    /**
     * Get availability for a particular location and dates
     * @return array
     */
    public function getAvailability(array $params = [])
    {
        $params = $this->mergeSiteAdminCredentials($params, false);

        try {
            $response = $this->client->request('GET', $this->hotel_uri, [
                'query' => $params,
            ]);
        } catch (Exception $e) {
            // Example: `416 Requested Range Not Satisfiable` response:
            // {"ArnResponse":{"Error":{"Type":"NoHotelsFoundException","Message":"No Hotels Found to satisfy your request."}}}
        }

        $json = json_decode((string) $response->getBody(), true);

        $this->stack[] = [
            'function' => (! empty($function)) ? $function : __FUNCTION__,
            'params' => $params,
            'code' => $response->getStatusCode(),
            'body' => $json,
            'response' => $response,
        ];

        Log::info('RoomSteals API: ' . __FUNCTION__, $this->stack);

        return end($this->stack);
    }
}
