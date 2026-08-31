<?php

namespace FractionalIndexing;

use FractionalIndexing\Exception\FractionalIndexingException;

class FractionalIndexing
{
    public const BASE_62_DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; // 0-9 + A-Z + a-z
    public const BASE_52_DIGITS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; // A-Z + a-z

    /** @var array<string, array<int, int>> */
    private static $digitIndexCache = [];

    /** @var array<string, array<int, string>> */
    private static $repeatedKeysCache = [];

    /** @var array<string, bool> */
    private static $validatedDigits = [];

    /** @var array<string, bool> */
    private static $validatedIntDigits = [];

    /**
     * @param string $digits
     * @return array<int, int>
     */
    private static function getDigitIndex($digits)
    {
        if (!isset(self::$digitIndexCache[$digits])) {
            $m = [];
            // prefill 0-255 with false/null or just rely on isset
            $len = strlen($digits);
            for ($i = 0; $i < $len; $i++) {
                $m[ord($digits[$i])] = $i;
            }
            self::$digitIndexCache[$digits] = $m;
        }
        return self::$digitIndexCache[$digits];
    }

    /**
     * @param string $a
     * @param string|null $b
     * @param string $digits
     * @param array<int, int> $lookup
     * @return string
     * @throws FractionalIndexingException
     */
    private static function midpoint($a, $b, $digits, $lookup)
    {
        $zero = $digits[0];
        if ($b !== null && strcmp($a, $b) >= 0) {
            throw new FractionalIndexingException($a . ' >= ' . $b);
        }
        if (substr($a, -1) === $zero || ($b !== null && substr($b, -1) === $zero)) {
            throw new FractionalIndexingException('trailing zero');
        }
        if ($b !== null) {
            $n = 0;
            $aLen = strlen($a);
            $bLen = strlen($b);
            while (true) {
                $charA = ($n < $aLen) ? $a[$n] : $zero;
                $charB = ($n < $bLen) ? $b[$n] : null;
                if ($charA !== $charB) {
                    break;
                }
                $n++;
            }
            if ($n > 0) {
                return substr($b, 0, $n) . self::midpoint(substr($a, $n), substr($b, $n), $digits, $lookup);
            }
        }
        $digitA = ($a !== '') ? (isset($lookup[ord($a[0])]) ? $lookup[ord($a[0])] : 0) : 0;
        $digitB = ($b !== null && $b !== '') ? (isset($lookup[ord($b[0])]) ? $lookup[ord($b[0])] : strlen($digits)) : strlen($digits);

        if ($digitB - $digitA > 1) {
            $midDigit = (int) round(0.5 * ($digitA + $digitB));
            return $digits[$midDigit];
        } else {
            if ($b !== null && strlen($b) > 1) {
                return substr($b, 0, 1);
            } else {
                return $digits[$digitA] . self::midpoint(substr($a, 1), null, $digits, $lookup);
            }
        }
    }

    /**
     * @param string $head
     * @param string $intDigits
     * @param array<int, int> $intLookup
     * @return int
     * @throws FractionalIndexingException
     */
    private static function getIntegerLength($head, $intDigits, $intLookup)
    {
        $headOrd = ord($head[0]);
        if (isset($intLookup[$headOrd])) {
            $i = $intLookup[$headOrd];
            if ($intDigits[$i] === $head) {
                $half = strlen($intDigits) / 2;
                return $i < $half ? (int) ($half - $i + 1) : (int) ($i - $half + 2);
            }
        }
        throw new FractionalIndexingException('invalid order key head: ' . $head);
    }

    /**
     * @param string $int
     * @param string $intDigits
     * @param array<int, int> $intLookup
     * @throws FractionalIndexingException
     */
    private static function validateInteger($int, $intDigits, $intLookup)
    {
        if (strlen($int) !== self::getIntegerLength($int[0], $intDigits, $intLookup)) {
            throw new FractionalIndexingException('invalid integer part of order key: ' . $int);
        }
    }

    /**
     * @param string $key
     * @param string $intDigits
     * @param array<int, int> $intLookup
     * @return string
     * @throws FractionalIndexingException
     */
    private static function getIntegerPart($key, $intDigits, $intLookup)
    {
        $integerPartLength = self::getIntegerLength($key[0], $intDigits, $intLookup);
        if ($integerPartLength > strlen($key)) {
            throw new FractionalIndexingException('invalid order key: ' . $key);
        }
        return substr($key, 0, $integerPartLength);
    }

    /**
     * @param string $key
     * @param string $digits
     * @param string $intDigits
     * @param array<int, int> $intLookup
     * @throws FractionalIndexingException
     */
    private static function validateOrderKey($key, $digits, $intDigits, $intLookup)
    {
        if (self::isSmallestInteger($key, $digits, $intDigits)) {
            throw new FractionalIndexingException('invalid order key: ' . $key);
        }
        $i = self::getIntegerPart($key, $intDigits, $intLookup);
        $f = substr($key, strlen($i));
        if (substr($f, -1) === $digits[0]) {
            throw new FractionalIndexingException('invalid order key: ' . $key);
        }
    }

    /**
     * @param string $x
     * @param string $digits
     * @param array<int, int> $lookup
     * @param string $intDigits
     * @param array<int, int> $intLookup
     * @return string|null
     * @throws FractionalIndexingException
     */
    private static function incrementInteger($x, $digits, $lookup, $intDigits, $intLookup)
    {
        self::validateInteger($x, $intDigits, $intLookup);
        $head = $x[0];
        $zero = $digits[0];
        $trailing = '';
        for ($i = strlen($x) - 1; $i >= 1; $i--) {
            $charOrd = ord($x[$i]);
            $d = (isset($lookup[$charOrd]) ? $lookup[$charOrd] : 0) + 1;
            if ($d === strlen($digits)) {
                $trailing = $zero . $trailing;
            } else {
                return $head . substr($x, 1, $i - 1) . $digits[$d] . $trailing;
            }
        }
        $headIndex = $intLookup[ord($head[0])];
        if ($headIndex === strlen($intDigits) - 1) {
            return null;
        }
        $h = $intDigits[$headIndex + 1];
        $lengthDelta = self::getIntegerLength($h, $intDigits, $intLookup) - self::getIntegerLength($head, $intDigits, $intLookup);
        return $h . ($lengthDelta > 0 ? $trailing . $zero : ($lengthDelta < 0 ? substr($trailing, 1) : $trailing));
    }

    /**
     * @param string $x
     * @param string $digits
     * @param array<int, int> $lookup
     * @param string $intDigits
     * @param array<int, int> $intLookup
     * @return string|null
     * @throws FractionalIndexingException
     */
    private static function decrementInteger($x, $digits, $lookup, $intDigits, $intLookup)
    {
        self::validateInteger($x, $intDigits, $intLookup);
        $head = $x[0];
        $last = $digits[strlen($digits) - 1];
        $trailing = '';
        for ($i = strlen($x) - 1; $i >= 1; $i--) {
            $charOrd = ord($x[$i]);
            $d = (isset($lookup[$charOrd]) ? $lookup[$charOrd] : 0) - 1;
            if ($d === -1) {
                $trailing = $last . $trailing;
            } else {
                return $head . substr($x, 1, $i - 1) . $digits[$d] . $trailing;
            }
        }
        $headIndex = $intLookup[ord($head[0])];
        if ($headIndex === 0) {
            return null;
        }
        $h = $intDigits[$headIndex - 1];
        $lengthDelta = self::getIntegerLength($h, $intDigits, $intLookup) - self::getIntegerLength($head, $intDigits, $intLookup);
        return $h . ($lengthDelta > 0 ? $trailing . $last : ($lengthDelta < 0 ? substr($trailing, 1) : $trailing));
    }

    /**
     * @param string $key
     * @param string $digits
     * @param string $intDigits
     * @return bool
     */
    private static function isSmallestInteger($key, $digits, $intDigits)
    {
        if (!isset(self::$repeatedKeysCache[$intDigits])) {
            self::$repeatedKeysCache[$intDigits] = [];
        }
        $zeroCode = ord($digits[0]);
        if (!isset(self::$repeatedKeysCache[$intDigits][$zeroCode])) {
            self::$repeatedKeysCache[$intDigits][$zeroCode] = $intDigits[0] . str_repeat($digits[0], (int) (strlen($intDigits) / 2));
        }
        return $key === self::$repeatedKeysCache[$intDigits][$zeroCode];
    }

    /**
     * @param string $s
     * @return bool
     */
    private static function isStrictlyAscending($s)
    {
        $len = strlen($s);
        for ($i = 1; $i < $len; $i++) {
            if (ord($s[$i - 1]) >= ord($s[$i])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $s
     * @return bool
     */
    private static function isSingleByte($s)
    {
        return !preg_match('/[\xC0-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF7][\x80-\xBF]{3}/', $s);
    }

    /**
     * @param string $digits
     * @throws FractionalIndexingException
     */
    private static function validateDigits($digits)
    {
        if (isset(self::$validatedDigits[$digits])) {
            return;
        }
        if (!self::isSingleByte($digits)) {
            throw new FractionalIndexingException('digits must be single-byte (char code 0-255): ' . $digits);
        }
        if (strlen($digits) < 2 || !self::isStrictlyAscending($digits)) {
            throw new FractionalIndexingException('digits must be at least 2 characters in strictly ascending character code order: ' . $digits);
        }
        self::$validatedDigits[$digits] = true;
    }

    /**
     * @param string $intDigits
     * @throws FractionalIndexingException
     */
    private static function validateIntDigits($intDigits)
    {
        if (isset(self::$validatedIntDigits[$intDigits])) {
            return;
        }
        if (!self::isSingleByte($intDigits)) {
            throw new FractionalIndexingException('intDigits must be single-byte (char code 0-255): ' . $intDigits);
        }
        $len = strlen($intDigits);
        if ($len < 2 || $len % 2 !== 0 || !self::isStrictlyAscending($intDigits)) {
            throw new FractionalIndexingException('intDigits must be an even number of at least 2 characters in strictly ascending character code order: ' . $intDigits);
        }
        self::$validatedIntDigits[$intDigits] = true;
    }

    /**
     * @param string|null $a
     * @param string|null $b
     * @param string|null $digits
     * @param string|null $intDigits
     * @return string
     * @throws FractionalIndexingException
     */
    public static function generateKeyBetween($a, $b, $digits = null, $intDigits = null)
    {
        if ($intDigits !== null) {
            self::validateIntDigits($intDigits);
        } else {
            $intDigits = $digits !== null ? $digits : self::BASE_52_DIGITS;
        }
        if ($digits !== null) {
            self::validateDigits($digits);
        } else {
            $digits = self::BASE_62_DIGITS;
        }

        $lookup = self::getDigitIndex($digits);
        $intLookup = self::getDigitIndex($intDigits);
        
        if ($a !== null) {
            self::validateOrderKey($a, $digits, $intDigits, $intLookup);
        }
        if ($b !== null) {
            self::validateOrderKey($b, $digits, $intDigits, $intLookup);
        }
        if ($a !== null && $b !== null) {
            if (strcmp($a, $b) > 0) {
                $temp = $a;
                $a = $b;
                $b = $temp;
            }
        }

        if ($a === null) {
            if ($b === null) {
                $head = $intDigits[(int) (strlen($intDigits) / 2)];
                return $head . $digits[0];
            }

            $ib = self::getIntegerPart($b, $intDigits, $intLookup);
            $fb = substr($b, strlen($ib));
            if (self::isSmallestInteger($ib, $digits, $intDigits)) {
                return $ib . self::midpoint('', $fb, $digits, $lookup);
            }
            if (strcmp($ib, $b) < 0) {
                return $ib;
            }
            $res = self::decrementInteger($ib, $digits, $lookup, $intDigits, $intLookup);
            if ($res === null) {
                throw new FractionalIndexingException('cannot decrement any more');
            }
            return $res;
        }

        if ($b === null) {
            $ia = self::getIntegerPart($a, $intDigits, $intLookup);
            $fa = substr($a, strlen($ia));
            $i = self::incrementInteger($ia, $digits, $lookup, $intDigits, $intLookup);
            return $i === null ? $ia . self::midpoint($fa, null, $digits, $lookup) : $i;
        }

        $ia = self::getIntegerPart($a, $intDigits, $intLookup);
        $fa = substr($a, strlen($ia));
        $ib = self::getIntegerPart($b, $intDigits, $intLookup);
        $fb = substr($b, strlen($ib));
        if ($ia === $ib) {
            return $ia . self::midpoint($fa, $fb, $digits, $lookup);
        }
        $i = self::incrementInteger($ia, $digits, $lookup, $intDigits, $intLookup);
        if ($i === null) {
            throw new FractionalIndexingException('cannot increment any more');
        }
        if (strcmp($i, $b) < 0) {
            return $i;
        }
        return $ia . self::midpoint($fa, null, $digits, $lookup);
    }

    /**
     * @param string|null $a
     * @param string|null $b
     * @param int $n
     * @param string|null $digits
     * @param string|null $intDigits
     * @return string[]
     * @throws FractionalIndexingException
     */
    public static function generateNKeysBetween($a, $b, $n, $digits = null, $intDigits = null)
    {
        if ($intDigits !== null) {
            self::validateIntDigits($intDigits);
        } else {
            $intDigits = $digits !== null ? $digits : self::BASE_52_DIGITS;
        }
        if ($digits !== null) {
            self::validateDigits($digits);
        } else {
            $digits = self::BASE_62_DIGITS;
        }

        if ($n < 0) {
            throw new FractionalIndexingException('n must be >= 0: ' . $n);
        }
        if ($n === 0) {
            return [];
        }
        if ($n === 1) {
            return [self::generateKeyBetween($a, $b, $digits, $intDigits)];
        }
        if ($b === null) {
            $c = self::generateKeyBetween($a, $b, $digits, $intDigits);
            $result = [$c];
            for ($i = 0; $i < $n - 1; $i++) {
                $c = self::generateKeyBetween($c, $b, $digits, $intDigits);
                $result[] = $c;
            }
            return $result;
        }
        if ($a === null) {
            $c = self::generateKeyBetween($a, $b, $digits, $intDigits);
            $result = [$c];
            for ($i = 0; $i < $n - 1; $i++) {
                $c = self::generateKeyBetween($a, $c, $digits, $intDigits);
                $result[] = $c;
            }
            return array_reverse($result);
        }
        $mid = (int) floor($n / 2);
        $c = self::generateKeyBetween($a, $b, $digits, $intDigits);
        
        $left = self::generateNKeysBetween($a, $c, $mid, $digits, $intDigits);
        $right = self::generateNKeysBetween($c, $b, $n - $mid - 1, $digits, $intDigits);
        
        return array_merge($left, [$c], $right);
    }
}
