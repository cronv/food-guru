<?php

declare(strict_types=1);

namespace App\Api\Validation;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Проверка ввода с правилами и сообщениями прежнего бэкенда — слой совместимости на время
 * переезда (MIGRATION.md). Фронтенд показывает людям errors[поле][0], а многие
 * контроллеры задают свои русские тексты, поэтому ответ должен совпадать дословно.
 *
 * Поддержаны правила, которые встречаются в бэкенде: required, sometimes, nullable,
 * string, integer, numeric, boolean, array, email, url, regex, in, min, max, between,
 * exists (с условием), file, image, mimes. Новое правило добавляется сюда вместе
 * с модулем, которому оно нужно.
 *
 *   $data = $validator->validate($request, [
 *       'q' => 'nullable|string|max:120',
 *       'steps' => 'nullable|array|max:40',
 *       'steps.*.body' => 'required|string|max:2000',
 *       'cover_media_id' => 'nullable|integer|exists:media,id,uploaded_by,'.$userId,
 *   ], ['steps.*.body.required' => 'В каждом шаге должен быть текст.']);
 *
 * Как $request->validate(): результат содержит только объявленные поля,
 * в том числе внутри массивов: необъявленный ключ (например, steps.*.chef_secret
 * у читателя) в данные не попадает.
 */
final class InputValidator
{
    private const TEMPLATES = [
        'required' => 'The :attribute field is required.',
        'string' => 'The :attribute field must be a string.',
        'integer' => 'The :attribute field must be an integer.',
        'numeric' => 'The :attribute field must be a number.',
        'boolean' => 'The :attribute field must be true or false.',
        'array' => 'The :attribute field must be an array.',
        'email' => 'The :attribute field must be a valid email address.',
        'url' => 'The :attribute field must be a valid URL.',
        'regex' => 'The :attribute field format is invalid.',
        'in' => 'The selected :attribute is invalid.',
        'exists' => 'The selected :attribute is invalid.',
        'file' => 'The :attribute field must be a file.',
        'image' => 'The :attribute field must be an image.',
        'mimes' => 'The :attribute field must be a file of type: :values.',
        'min.numeric' => 'The :attribute field must be at least :min.',
        'min.string' => 'The :attribute field must be at least :min characters.',
        'min.array' => 'The :attribute field must have at least :min items.',
        'min.file' => 'The :attribute field must be at least :min kilobytes.',
        'max.numeric' => 'The :attribute field must not be greater than :max.',
        'max.string' => 'The :attribute field must not be greater than :max characters.',
        'max.array' => 'The :attribute field must not have more than :max items.',
        'max.file' => 'The :attribute field must not be greater than :max kilobytes.',
        'between.numeric' => 'The :attribute field must be between :min and :max.',
        'between.string' => 'The :attribute field must be between :min and :max characters.',
        'between.array' => 'The :attribute field must have between :min and :max items.',
        'between.file' => 'The :attribute field must be between :min and :max kilobytes.',
    ];

    /** Правило image не пускает SVG: в нём может быть скрипт. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];

    /** Поля, которые не обрезаются по краям (пароли). */
    private const UNTRIMMED = ['password', 'password_confirmation', 'current_password'];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Ввод запроса: query для GET; для остальных — JSON-тело или форма, файлы и query.
     *
     * @return array<string, mixed>
     */
    public static function input(Request $request): array
    {
        if ('GET' === $request->getMethod() || 'HEAD' === $request->getMethod()) {
            return self::clean($request->query->all());
        }

        $body = 'json' === $request->getContentTypeFormat() && '' !== $request->getContent() ? $request->toArray() : $request->request->all();

        return array_replace_recursive(self::clean($body + $request->query->all()), $request->files->all());
    }

    /**
     * @param array<string, string>     $rules    поле => 'правило|правило:параметр'
     * @param array<string, string>     $messages 'поле.правило' или 'поле' => свой текст
     * @param array<string, mixed>|null $input    по умолчанию — ввод запроса
     *
     * @return array<string, mixed>
     *
     * @throws ValidationFailed
     */
    public function validate(Request $request, array $rules, array $messages = [], ?array $input = null): array
    {
        $input ??= self::input($request);
        $errors = [];

        // Правила с * раскрываются и встают после
        // обычных полей — от этого зависит порядок ошибок в ответе.
        uksort($rules, static fn (string $a, string $b): int => (int) str_contains($a, '*') <=> (int) str_contains($b, '*'));

        foreach ($rules as $field => $definition) {
            $parsed = $this->parse($definition);
            foreach ($this->expand($field, $input) as $path => [$value, $present]) {
                foreach ($this->check($path, $field, $value, $present, $parsed, $messages) as $error) {
                    $errors[$path][] = $error;
                }
            }
        }

        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        return $this->validated(array_keys($rules), $input);
    }

    /**
     * Только объявленные ключи: верхний уровень и то, что разрешено правилами вида x.*.y.
     *
     * @param list<string>         $fields
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function validated(array $fields, array $input): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (str_contains($field, '*') || !\array_key_exists($field, $input)) {
                continue;
            }

            $children = [];
            foreach ($fields as $other) {
                if (str_starts_with($other, $field.'.*.')) {
                    $children[] = substr($other, \strlen($field) + 3);
                }
            }

            $value = $input[$field];
            if ([] !== $children && \is_array($value)) {
                $value = array_map(
                    static fn (mixed $row): mixed => \is_array($row) ? array_intersect_key($row, array_flip($children)) : $row,
                    $value,
                );
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /**
     * Обрезка пробелов по краям и пустые строки → null.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function clean(array $data, string $prefix = ''): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::clean($value, $prefix.$key.'.');
            } elseif (\is_string($value)) {
                $value = \in_array($prefix.$key, self::UNTRIMMED, true) ? $value : trim($value);
                $data[$key] = '' === $value ? null : $value;
            }
        }

        return $data;
    }

    /** @return list<array{0: string, 1: list<string>}> */
    private function parse(string $definition): array
    {
        return array_map(static function (string $rule): array {
            [$name, $params] = array_pad(explode(':', $rule, 2), 2, '');

            // Выражение regex не делится по запятым.
            if ('regex' === $name) {
                return [$name, [$params]];
            }

            return [$name, '' === $params ? [] : explode(',', $params)];
        }, explode('|', $definition));
    }

    /**
     * Раскрывает шаблоны: 'tags.*' → tags.0, tags.1…; 'steps.*.body' → steps.0.body…
     * У каждого элемента своя ошибка.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, array{0: mixed, 1: bool}> путь => [значение, есть ли ключ]
     */
    private function expand(string $field, array $input): array
    {
        $nodes = ['' => [$input, true]];
        foreach (explode('.', $field) as $segment) {
            $next = [];
            foreach ($nodes as $path => [$node, $present]) {
                $prefix = '' === $path ? '' : $path.'.';
                if ('*' === $segment) {
                    foreach (\is_array($node) ? $node : [] as $i => $child) {
                        $next[$prefix.$i] = [$child, true];
                    }
                } else {
                    $has = \is_array($node) && \array_key_exists($segment, $node);
                    $next[$prefix.$segment] = [$has ? $node[$segment] : null, $has];
                }
            }
            $nodes = $next;
        }

        return $nodes;
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $rules
     * @param array<string, string>                   $messages
     *
     * @return list<string> все сообщения поля: проверка не останавливается
     *                      на первой ошибке, кроме bail и проваленного required
     */
    private function check(string $path, string $field, mixed $value, bool $present, array $rules, array $messages): array
    {
        $names = array_column($rules, 0);

        // sometimes: нет ключа — нет и проверки.
        if (!$present && \in_array('sometimes', $names, true)) {
            return [];
        }

        // null (и пустая строка — она уже стала null) проверяет только required.
        if (null === $value) {
            return \in_array('required', $names, true) ? [$this->message($path, $field, 'required', [], $messages)] : [];
        }

        $bail = \in_array('bail', $names, true);
        $errors = [];

        $kind = match (true) {
            $value instanceof UploadedFile => 'file',
            \in_array('array', $names, true) => 'array',
            \in_array('integer', $names, true), \in_array('numeric', $names, true) => 'numeric',
            default => 'string',
        };

        foreach ($rules as [$rule, $params]) {
            $ok = match ($rule) {
                // Пустой массив и файл без пути — тоже «нет значения».
                'required' => !([] === $value || ($value instanceof UploadedFile && '' === $value->getPath())),
                'nullable', 'sometimes', 'bail' => true,
                'string' => \is_string($value),
                'integer' => \is_scalar($value) && false !== filter_var($value, \FILTER_VALIDATE_INT),
                'numeric' => is_numeric($value),
                'boolean' => \in_array($value, [true, false, 0, 1, '0', '1'], true),
                'array' => \is_array($value),
                'email' => \is_string($value) && false !== filter_var($value, \FILTER_VALIDATE_EMAIL),
                'url' => self::isUrl($value, $params),
                'regex' => \is_string($value) && 1 === preg_match($params[0], $value),
                'in' => \is_array($value)
                    ? [] === array_diff(array_map('strval', $value), $params)
                    : \is_scalar($value) && \in_array((string) $value, $params, true),
                'min' => $this->size($value, $kind) >= (float) $params[0],
                'max' => $this->size($value, $kind) <= (float) $params[0],
                'between' => $this->size($value, $kind) >= (float) $params[0] && $this->size($value, $kind) <= (float) $params[1],
                'exists' => \is_scalar($value) && $this->exists($params, $field, $value),
                'file' => $value instanceof UploadedFile && $value->isValid(),
                'image' => self::hasExtension($value, self::IMAGE_EXTENSIONS),
                'mimes' => self::hasExtension($value, $params),
                default => throw new \LogicException("Правило «{$rule}» не поддержано InputValidator."),
            };

            if (!$ok) {
                $template = \in_array($rule, ['min', 'max', 'between'], true) ? $rule.'.'.$kind : $rule;
                $replace = match ($rule) {
                    'between' => ['min' => $params[0], 'max' => $params[1]],
                    'mimes' => ['values' => implode(', ', $params)],
                    default => [$rule => $params[0] ?? ''],
                };

                $errors[] = $this->message($path, $field, $template, $replace, $messages);

                // Дальше не проверяем после bail или проваленного required.
                if ($bail || 'required' === $rule) {
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Тип файла — по содержимому (guessExtension);
     * jpg и jpeg взаимозаменяемы.
     *
     * @param list<string> $allowed
     */
    private static function hasExtension(mixed $value, array $allowed): bool
    {
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            return false;
        }
        if (\in_array('jpg', $allowed, true) || \in_array('jpeg', $allowed, true)) {
            $allowed = array_merge($allowed, ['jpg', 'jpeg']);
        }

        return \in_array($value->guessExtension(), $allowed, true);
    }

    /** @param list<string> $protocols */
    private static function isUrl(mixed $value, array $protocols): bool
    {
        if (!\is_string($value) || false === filter_var($value, \FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, \PHP_URL_SCHEME));

        return [] === $protocols || \in_array($scheme, $protocols, true);
    }

    private function size(mixed $value, string $kind): float
    {
        return match ($kind) {
            // Размер файла в правилах — в килобайтах.
            'file' => $value instanceof UploadedFile ? $value->getSize() / 1024 : 0.0,
            'array' => \is_array($value) ? \count($value) : 0,
            // Для числового значения — оно само, иначе длина строки.
            'numeric' => is_numeric($value) ? (float) $value : (\is_string($value) ? mb_strlen($value) : 0.0),
            default => \is_string($value) ? mb_strlen($value) : 0,
        };
    }

    /**
     * exists:таблица,колонка[,колонка,значение…] — дополнительные пары — условия WHERE.
     * Имена — из кода правил, не из запроса.
     *
     * @param list<string> $params
     */
    private function exists(array $params, string $field, int|float|string|bool $value): bool
    {
        $q = $this->db->createQueryBuilder()->select('1')->from($this->db->quoteSingleIdentifier($params[0]))
            ->where($this->db->quoteSingleIdentifier($params[1] ?? $field).' = :value')->setParameter('value', $value)
            ->setMaxResults(1);

        for ($i = 2; isset($params[$i], $params[$i + 1]); $i += 2) {
            $q->andWhere($this->db->quoteSingleIdentifier($params[$i]).' = :w'.$i)->setParameter('w'.$i, $params[$i + 1]);
        }

        return false !== $q->executeQuery()->fetchOne();
    }

    /**
     * Свой текст ищется так: 'поле.правило' (в том числе с *), затем 'поле'.
     *
     * @param array<string, string> $params
     * @param array<string, string> $messages
     */
    private function message(string $path, string $field, string $template, array $params, array $messages): string
    {
        $rule = explode('.', $template)[0];
        $text = $messages[$field.'.'.$rule] ?? $messages[$path.'.'.$rule] ?? $messages[$field] ?? self::TEMPLATES[$template];

        $replace = [':attribute' => str_replace('_', ' ', $path)];
        foreach ($params as $name => $value) {
            $replace[':'.$name] = $value;
        }

        return strtr($text, $replace);
    }
}
