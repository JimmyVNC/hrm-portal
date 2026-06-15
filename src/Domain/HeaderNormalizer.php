<?php

function normalizeDomainHeaderValue($value) {
    $value = is_string($value) ? $value : (string) $value;
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = str_replace("\xC2\xA0", ' ', $value);
    $value = preg_replace('/\s+/u', ' ', trim($value));
    return strtoupper($value);
}
