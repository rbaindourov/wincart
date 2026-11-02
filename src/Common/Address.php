<?php

declare(strict_types=1);

namespace WinCart\Common;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Readonly Address value object encapsulating physical billing and shipping destinations.
 */
final readonly class Address implements JsonSerializable, Stringable
{
    public string $firstName;
    public string $lastName;
    public string $address1;
    public ?string $address2;
    public string $city;
    public string $state;
    public string $postalCode;
    public string $country;
    public ?string $phone;
    public ?string $company;

    public function __construct(
        string $firstName,
        string $lastName,
        string $address1,
        string $city,
        string $state,
        string $postalCode,
        string $country = 'US',
        ?string $address2 = null,
        ?string $phone = null,
        ?string $company = null
    ) {
        $trimmedFirst = trim($firstName);
        $trimmedLast = trim($lastName);
        $trimmedAddress1 = trim($address1);
        $trimmedCity = trim($city);
        $trimmedState = trim($state);
        $trimmedPostal = trim($postalCode);
        $trimmedCountry = strtoupper(trim($country));

        if ($trimmedFirst === '') {
            throw new InvalidArgumentException('First name cannot be empty');
        }
        if ($trimmedLast === '') {
            throw new InvalidArgumentException('Last name cannot be empty');
        }
        if ($trimmedAddress1 === '') {
            throw new InvalidArgumentException('Address line 1 cannot be empty');
        }
        if ($trimmedCity === '') {
            throw new InvalidArgumentException('City cannot be empty');
        }
        if ($trimmedState === '') {
            throw new InvalidArgumentException('State cannot be empty');
        }
        if ($trimmedPostal === '') {
            throw new InvalidArgumentException('Postal code cannot be empty');
        }
        if ($trimmedCountry === '') {
            throw new InvalidArgumentException('Country code cannot be empty');
        }

        $this->firstName = $trimmedFirst;
        $this->lastName = $trimmedLast;
        $this->address1 = $trimmedAddress1;
        $this->address2 = $address2 !== null && trim($address2) !== '' ? trim($address2) : null;
        $this->city = $trimmedCity;
        $this->state = $trimmedState;
        $this->postalCode = $trimmedPostal;
        $this->country = $trimmedCountry;
        $this->phone = $phone !== null && trim($phone) !== '' ? trim($phone) : null;
        $this->company = $company !== null && trim($company) !== '' ? trim($company) : null;
    }

    /**
     * Customer full name.
     */
    public function getFullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    /**
     * Multi-line formatted postal address.
     */
    public function getFormattedAddress(): string
    {
        $lines = [];
        if ($this->company !== null) {
            $lines[] = $this->company;
        }
        $lines[] = $this->getFullName();
        $lines[] = $this->address1;
        if ($this->address2 !== null) {
            $lines[] = $this->address2;
        }
        $lines[] = "{$this->city}, {$this->state} {$this->postalCode}";
        $lines[] = $this->country;
        if ($this->phone !== null) {
            $lines[] = "Tel: {$this->phone}";
        }

        return implode("\n", $lines);
    }

    /**
     * Stringable interface.
     */
    public function __toString(): string
    {
        return $this->getFormattedAddress();
    }

    /**
     * Convert value object to database / API associative array.
     */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'address_1' => $this->address1,
            'address_2' => $this->address2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'phone' => $this->phone,
        ];
    }

    /**
     * Hydrate instance from associative array (supports snake_case and camelCase keys).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            firstName: (string) ($data['first_name'] ?? $data['firstName'] ?? ''),
            lastName: (string) ($data['last_name'] ?? $data['lastName'] ?? ''),
            address1: (string) ($data['address_1'] ?? $data['address1'] ?? ''),
            city: (string) ($data['city'] ?? ''),
            state: (string) ($data['state'] ?? ''),
            postalCode: (string) (
                $data['postal_code'] ?? $data['postalCode'] ?? $data['postcode'] ?? $data['zip'] ?? ''
            ),
            country: (string) ($data['country'] ?? 'US'),
            address2: isset($data['address_2']) || isset($data['address2'])
                ? (string) ($data['address_2'] ?? $data['address2'])
                : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            company: isset($data['company']) ? (string) $data['company'] : null
        );
    }

    /**
     * JSON serialization format.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
