<?php

namespace App\Domain\Privacy;

use Attribute;

/**
 * Marca i campi personali di un modello (09, "Privacy by design nel codice"). Alimenta audit
 * (valore sostituito da "[personal]"), export e anonimizzazione (I22).
 *
 * Si applica alla classe del modello, una volta per campo o con più campi:
 *
 *     #[PersonalData('first_name', 'last_name')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class PersonalData
{
    /** @var list<string> */
    public readonly array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = array_values($fields);
    }
}
