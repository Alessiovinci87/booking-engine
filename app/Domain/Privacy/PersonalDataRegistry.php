<?php

namespace App\Domain\Privacy;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Legge l'attributo #[PersonalData] dei modelli (anche ereditato dalle classi genitrici).
 */
final class PersonalDataRegistry
{
    /** @var array<class-string, list<string>> */
    private static array $cache = [];

    /**
     * @param  Model|class-string<Model>  $model
     * @return list<string>
     */
    public static function fieldsOf(Model|string $model): array
    {
        $class = is_string($model) ? $model : $model::class;

        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $fields = [];

        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getAttributes(PersonalData::class) as $attribute) {
                array_push($fields, ...$attribute->newInstance()->fields);
            }
        }

        return self::$cache[$class] = array_values(array_unique($fields));
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    public static function isPersonal(Model|string $model, string $field): bool
    {
        return in_array($field, self::fieldsOf($model), true);
    }
}
