<?php

namespace NateRitter\LaravelRoomstealsApi;

use GuzzleHttp\Client;

class LaravelRoomstealsApi
{
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
    public function __construct() {
        if (! $this->apiCredentialsExist()) {
            throw new \Exception("RoomSteals API credentials do not exist in .env file");
        }
        $this->client = new Client();
        $this->getAdminToken();
    }

    /**
     * Checks if the API credentials are in the .env file
     * @return boolean
     */
    private function apiCredentialsExist() {
        if (empty(config('laravelroomstealsapi.roomsteals_api_username'))
            || empty(config('laravelroomstealsapi.roomsteals_api_password'))
            || empty(config('laravelroomstealsapi.roomsteals_api_site_admin_username'))
        ) {
           return false;
        }
        return true;
    }

    /**
     * Get the current member's SSO URL to the portal
     * @return string
     */
    public function getPortalUri() {
        return $this->constructPortalUri();
    }

    /**
     * Construct and return the current member's SSO URL to the portal
     * @return string
     */
    public function constructPortalUri() {
        return $this->portal_uri.'?memberToken='.urlencode($this->member_token);
    }

    /**
     * Construct and upsert a member
     * @param  array  $params [description]
     * @return [type]         [description]
     */
    public function constructAndUpsertMember(array $params = []) {
        $memberData = $this->constructMemberObject($params);

        return $this->upsertMember(['memberData' => $memberData]);
    }

    /**
     * Create a memberData object and then json_encode it
     * @param  array  $params
     * @return string
     */
    public function constructMemberObject(array $params = []) {
        $user = new \stdClass();
        $user->ReferralId = $params['email'] ?? '';
        $user->FirstName = $params['first_name'] ?? '';
        $user->LastName = $params['last_name'] ?? '';
        $user->Email = $params['email'] ?? '';
        // $user->Address1 = $params['address'] ?? '';
        // $user->HomePhone = $params['home_phone'] ?? '';

        $memberData = new \stdClass();
        $memberData->Names = [$user];

        return json_encode($memberData);
    }

    /**
     * Gets an Admin Token
     * @param  array  $params
     * @return array
     */
    public function getAdminToken(array $params = []) {
        $this->query = $this->mergeSiteAdminCredentials($params);

        $response = $this->client->request('GET', $this->member_uri, ['query' => $this->query]);

        $json = json_decode((string) $response->getBody());

        if (isset($json->CurrentToken)) {
            $this->admin_token = urldecode($json->CurrentToken);
        }

        $this->stack[] = [
            'function' => __FUNCTION__,
            'params' => $params,
            'code' => $response->getStatusCode(),
            'body' => $json,
            'response' => $response,
        ];

        return end($this->stack);
    }

    /**
     * Creates a Member
     * @param  array  $params
     * @return array
     */
    public function createMember(array $params = []) {
        extract($this->upsertMember($params), __FUNCTION__);

        return end($this->stack);
    }

    /**
     * Updates a Member
     * @param  array  $params
     * @return array
     */
    public function updateMember(array $params = []) {
        extract($this->upsertMember($params), __FUNCTION__);

        return end($this->stack);
    }

    /**
     * Update or insert/create the member data
     * @param  array  $params
     * @param  mixed  $function Name of function calling this one, or null by default
     * @return array
     */
    private function upsertMember(array $params = [], $function = null) {
        $this->query = $this->mergeSiteAdminToken($params);

        $response = $this->client->request('POST', $this->member_uri, [
            'form_params' => $this->query
        ]);

        $json = json_decode((string) $response->getBody());

        if (isset($json->CurrentToken)) {
            $this->member_token = urldecode($json->CurrentToken);
        }

        $this->stack[] = [
            'function' => (! empty($function)) ? $function : __FUNCTION__,
            'params' => $params,
            'code' => $response->getStatusCode(),
            'body' => $json,
            'response' => $response,
        ];

        return end($this->stack);
    }

    /**
     * Merges the site admin credentials into the request
     * @param  array  $query
     * @return array
     */
    private function mergeSiteAdminCredentials(array $query = []) {
        $credentials = [
            'username' => config('laravelroomstealsapi.roomsteals_api_username'),
            'password' => config('laravelroomstealsapi.roomsteals_api_password'),
            'token' => 'ARNUSER-'.config('laravelroomstealsapi.roomsteals_api_site_admin_username'),
            'siteid' => config('laravelroomstealsapi.roomsteals_api_site_id'),
        ];

        return array_merge($query, $credentials);
    }

    /**
     * Merges the site admin token into the request
     * @param  array  $query
     * @return array
     */
    private function mergeSiteAdminToken(array $query = []) {
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
    public function getDealsLocations(array $params = []) {
        $params['type'] = 'findfeaturedlocationdeals';

        $response = $this->client->request('GET', $this->deals_uri, [
            'query' => $params
        ]);

        $json = json_decode((string) $response->getBody());

        $this->stack[] = [
            'function' => (! empty($function)) ? $function : __FUNCTION__,
            'params' => $params,
            'code' => $response->getStatusCode(),
            'body' => $json,
            'response' => $response,
        ];

        return end($this->stack);
    }

    /**
     * Get the locations with the best deals
     * @return array
     */
    public function getDealsHotels(array $params = []) {
        $params['type'] = 'findfeaturedlocationdeals';

        $response = $this->client->request('GET', $this->deals_uri, [
            'query' => $params
        ]);

        $json = json_decode((string) $response->getBody());

        $this->stack[] = [
            'function' => (! empty($function)) ? $function : __FUNCTION__,
            'params' => $params,
            'code' => $response->getStatusCode(),
            'body' => $json,
            'response' => $response,
        ];

        return end($this->stack);
    }
}
