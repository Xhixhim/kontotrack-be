<?php

namespace App\Rules;

use App\Support\KosovoIdentifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CounterpartyIdentifier implements ValidationRule
{
    public function __construct(private string $countryCode) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || KosovoIdentifier::isValidForCounterparty($value, $this->countryCode)) {
            return;
        }

        $fail(strtoupper($this->countryCode) === 'XK'
            ? 'For a Kosovo business enter the 9-digit NUI or fiscal number; for an individual, the 10-digit personal number.'
            : 'Enter the identifier with letters and digits only, 2 to 32 characters.');
    }
}
