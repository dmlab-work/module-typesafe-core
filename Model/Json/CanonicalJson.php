<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Json;

/**
 * Deterministic, unambiguous JSON: assoc arrays sorted by key, lists and \stdClass maps keep their order.
 */
class CanonicalJson
{
    /** Invalid UTF-8 (common in imported catalogs) becomes U+FFFD instead of failing the request forever. */
    public const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Sole key of an encoded \stdClass; array keys starting with it are escaped, so no array can produce it. */
    private const MAP_TAG = "\0";

    /** Same limit as json_encode(); also stops self-referencing arrays. */
    private const MAX_DEPTH = 512;

    /**
     * Encode a value canonically.
     *
     * @param mixed $value
     * @return string
     * @throws \JsonException
     */
    public function encode(mixed $value): string
    {
        return $this->write($value, 0);
    }

    /**
     * Encode a value recursively; objects are written by hand since json_encode() drops "\0"-prefixed properties.
     *
     * @param mixed $value
     * @param int $depth
     * @return string
     * @throws \JsonException
     */
    private function write(mixed $value, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new \JsonException('Maximum stack depth exceeded', JSON_ERROR_DEPTH);
        }
        if ($value instanceof \stdClass) {
            // Order-significant map: {"\u0000": [[key, value], ...]} so sorting can never apply to it.
            $pairs = [];
            foreach (get_object_vars($value) as $key => $item) {
                $pairs[] = '[' . json_encode((string)$key, self::FLAGS) . ',' . $this->write($item, $depth + 2) . ']';
            }

            return '{' . json_encode(self::MAP_TAG, self::FLAGS) . ':[' . implode(',', $pairs) . ']}';
        }
        if (!is_array($value)) {
            return json_encode($value, self::FLAGS);
        }
        if (array_is_list($value)) {
            return '[' . implode(',', array_map(fn ($item) => $this->write($item, $depth + 1), $value)) . ']';
        }

        $map = [];
        foreach ($value as $key => $item) {
            $key = (string)$key;
            $map[str_starts_with($key, self::MAP_TAG) ? self::MAP_TAG . $key : $key] = $item;
        }
        ksort($map, SORT_STRING);

        // An object even when the sorted keys form a list, matching how the body encodes it.
        $members = [];
        foreach ($map as $key => $item) {
            $members[] = json_encode((string)$key, self::FLAGS) . ':' . $this->write($item, $depth + 1);
        }

        return '{' . implode(',', $members) . '}';
    }
}
