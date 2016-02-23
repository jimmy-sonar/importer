<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;

class AccountImporter
{
    private $uri;
    private $username;
    private $password;
    private $client;

    /**
     * Data stored to limit necessary queries
     */
    private $countries;
    private $subDivisions = [];
    private $counties = [];

    /**
     * Importer constructor.
     */
    public function __construct()
    {
        $dotenv = new \Dotenv\Dotenv(__DIR__);
        $dotenv->load();
        $dotenv->required(
            [
                'URI',
                'USERNAME',
                'PASSWORD',
            ]
        );

        $this->uri = getenv("URI");
        $this->username = getenv("USERNAME");
        $this->password = getenv("PASSWORD");

        $this->client = new \GuzzleHttp\Client();
    }

    /**
     * @param $pathToImportFile
     * @return array
     * @throws InvalidArgumentException
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($handle);

            mkdir(__DIR__ . "/../log_output");

            $failureLogName = tempnam(__DIR__ . "/../log_output","account_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(__DIR__ . "/../log_output","account_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
            ];

            $response = $this->client->get($this->uri . "/api/v1/_data/countries", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
            ]);

            $this->countries = json_decode($response->getBody())->data;

            $row = 0;
            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $row = 1;
                try {
                    $payload = $this->buildPayload($data);
                    $this->client->post($this->uri . "/api/v1/accounts", [
                        'headers' => [
                            'Content-Type' => 'application/json; charset=UTF8',
                            'timeout' => 30,
                        ],
                        'auth' => [
                            $this->username,
                            $this->password,
                        ],
                        'json' => $payload,
                    ]);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    fwrite($failureLog,"Row $row failed: {$body->data}");
                }
                catch (Exception $e)
                {
                    fwrite($failureLog,"Row $row failed: {$e->getMessage()}");
                    continue;
                }

                fwrite($successLog,"Row $row succeeded for account ID {$payload['id']}");
            }
        }
        else
        {
            throw new InvalidArgumentException("File could not be opened.");
        }

        return $returnData;
    }

    /**
     * Validate all the data in the import file.
     * @param $fileHandle
     */
    private function validateImportFile($fileHandle)
    {
        $requiredColumns = [ 0,1,2,3,7,9,10,13,16 ];

        $row = 0;
        while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
            $row++;
            foreach ($requiredColumns as $colNumber)
            {
                if (trim($data[$colNumber]) == '')
                {
                    throw new InvalidArgumentException("In the account import, column number " . ($colNumber+1) . " is required, and it is empty on row $row.");
                }
            }
        }

        return;
    }

    /**
     * Return a formatted address for use in the API import. Throws an exception if something is invalid in the address.
     * @param $data
     * @return array
     */
    private function returnFormattedAddress($data)
    {
        $unformattedAddress = [
            'line1' => trim($data[7]),
            'line2' => trim($data[8]),
            'city' => trim($data[9]),
            'state' => trim($data[10]),
            'county' => trim($data[11]),
            'zip' => trim($data[12]),
            'country' => trim($data[13]),
            'latitude' => trim($data[14]),
            'longitude' => trim($data[15]),
        ];

        if (!array_key_exists($unformattedAddress['country'],$this->countries))
        {
            throw new InvalidArgumentException($unformattedAddress['country'] . " is not a valid country.");
        }

        try {
            $validatedAddressResponse = $this->client->post($this->uri . "/api/v1/_data/validate_address", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
                'json' => $unformattedAddress,
            ]);

            $address = (array)json_decode($validatedAddressResponse->getBody())->data;
            if ($unformattedAddress['latitude'] && $unformattedAddress['longitude'])
            {
                $address['latitude'] = $unformattedAddress['latitude'];
                $address['longitude'] = $unformattedAddress['longitude'];
            }
            return $address;
        }
        catch (Exception $e)
        {
            /**
             * The address failed to validate, but we will still attempt to validate individual parts of it to see if it can be used.
             */
            if (!array_key_exists($unformattedAddress['country'],$this->subDivisions))
            {
                $subDivisions = $this->client->get($this->uri . "/api/v1/_data/subdivisions/{$unformattedAddress['country']}", [
                    'headers' => [
                        'Content-Type' => 'application/json; charset=UTF8',
                        'timeout' => 30,
                    ],
                    'auth' => [
                        $this->username,
                        $this->password,
                    ],
                ]);

                $subDivisionArray = (array)json_decode($subDivisions->getBody());
                $this->subDivisions[$unformattedAddress['country']] = $subDivisionArray;
            }

            if (!array_key_exists($unformattedAddress['state'],$this->subDivisions[$unformattedAddress['country']]))
            {
                throw new InvalidArgumentException($unformattedAddress['state'] . " is not a valid subdivision for " . $unformattedAddress['country']);
            }

            if ($unformattedAddress['country'] == "US")
            {
                if (!$unformattedAddress['county'])
                {
                    throw new InvalidArgumentException("The address failed to validate, and a county is required for addresses in the US.");
                }

                if (!array_key_exists($unformattedAddress['state'],$this->counties))
                {
                    $counties = $this->client->get($this->uri . "/api/v1/_data/counties/{$unformattedAddress['state']}", [
                        'headers' => [
                            'Content-Type' => 'application/json; charset=UTF8',
                            'timeout' => 30,
                        ],
                        'auth' => [
                            $this->username,
                            $this->password,
                        ],
                    ]);

                    $countyArray = (array)json_decode($counties->getBody());
                    $this->counties[$unformattedAddress['state']] = $countyArray;
                }

                if (!in_array($unformattedAddress['county'],$this->counties[$unformattedAddress['state']]))
                {
                    throw new InvalidArgumentException("The county is not a valid county for the state.");
                }
            }

            return $unformattedAddress;
        }
    }

    /**
     * @param $data
     * @return array
     */
    private function buildPayload($data)
    {
        $payload = [
            'id' => (int)$data[0],
            'name' => trim($data[1]),
            'account_type_id' => (int)$data[2],
            'account_status_id' => (int)$data[3],
            'contact_name' => trim($data[16]),
        ];
        $formattedAddress = $this->returnFormattedAddress($data);

        $payload = array_merge($payload,$formattedAddress);

        /**
         * We don't do a ton of validation here, as the API call will fail if this data is invalid anyway.
         */
        if (trim($data[4]))
        {
            $payload['account_groups'] = explode(",",trim($data[4]));
        }
        if (trim($data[5]))
        {
            $payload['sub_accounts'] = explode(",",trim($data[5]));
        }
        if (trim($data[6]))
        {
            $payload['next_bill_date'] = trim($data[6]);
        }
        if (trim($data[17]))
        {
            $payload['role'] = trim($data[17]);
        }
        if (trim($data[18]))
        {
            $payload['email_address'] = trim($data[18]);
        }
        if (trim($data[19]))
        {
            $payload['email_message_categories'] = explode(",",trim($data[19]));
        }
        else
        {
            $payload['email_message_categories'] = [];
        }

        $phoneNumbers = [];
        if (trim($data[20]))
        {
            $phoneNumbers['work'] = [
                'number' => trim($data[20]),
                'extension' => trim($data[21]),
            ];
        }
        if (trim($data[21]))
        {
            $phoneNumbers['home'] = [
                'number' => trim($data[21]),
                'extension' => null,
            ];
        }
        if (trim($data[22]))
        {
            $phoneNumbers['mobile'] = [
                'number' => trim($data[22]),
                'extension' => null,
            ];
        }
        if (trim($data[23]))
        {
            $phoneNumbers['fax'] = [
                'number' => trim($data[23]),
                'extension' => null,
            ];
        }

        if (count($phoneNumbers) > 0)
        {
            $payload['phone_numbers'] = $phoneNumbers;
        }

        return $payload;
    }
}