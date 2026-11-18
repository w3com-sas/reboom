<?php

namespace W3com\BoomBundle\Utils;

class RawRequest
{
    private string $request;
    private string $response = '';
    private array $results = [];
    private ?array $flattenedResults = null;

    public function __construct(string $request)
    {
        $this->setRequest($request);
    }

    public function handleResponse(?string $response): void
    {
        if ($response === null) {
            $results = [];
        } else {
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \UnexpectedValueException('Error RawRequest : invalid response.', 0, $e);
            }

            if (!is_array($decoded)) {
                throw new \UnexpectedValueException('Error RawRequest : invalid response.');
            }
            if (array_key_exists('odata.metadata', $decoded)) {
                if (array_key_exists('odata.etag', $decoded)) {
                    $results = $decoded;
                } elseif (isset($decoded['value']) && is_array($decoded['value'])) {
                    $results = $decoded['value'];
                } else {
                    throw new \UnexpectedValueException('Error RawRequest : invalid response.');
                }
            } elseif ($decoded === []) {
                $results = [];
            } else {
                throw new \UnexpectedValueException('Error RawRequest : invalid response.');
            }
        }

        $this->response = $response ?? '';
        $this->results = $results;
        $this->flattenedResults = null;
    }

    public function setRequest(string $request): void
    {
        $this->request = $request;
    }

    public function getRequest(): string
    {
        return $this->request;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getResponse(): string
    {
        return $this->response;
    }

    public function getFlattenedResults(): array
    {
        if ($this->flattenedResults === null) {
            $flattened = [];
            foreach ($this->results as $result) {
                if (!is_array($result)) {
                    throw new \UnexpectedValueException('Error RawRequest : cannot flatten response.');
                }
                $line = [];
                foreach ($result as $data) {
                    if (!is_array($data)) {
                        throw new \UnexpectedValueException('Error RawRequest : cannot flatten response.');
                    }
                    $line = array_merge($line, $data);
                }
                $flattened[] = $line;
            }
            $this->flattenedResults = $flattened;
        }
        return $this->flattenedResults;
    }
}
