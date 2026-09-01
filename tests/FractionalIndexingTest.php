<?php

namespace LucFernandes\FractionalIndexing\Tests;

use LucFernandes\FractionalIndexing\FractionalIndexing;
use LucFernandes\FractionalIndexing\Exception\FractionalIndexingException;
use PHPUnit\Framework\TestCase;

/**
 * Suíte de testes da biblioteca Fractional Indexing.
 *
 * É vital que esta suíte passe idêntica e rigorosamente em todas as versões do PHP suportadas, 
 * assegurando que não existam desvios semânticos (ex: problemas com arrays, strcmp, etc).
 */
class FractionalIndexingTest extends TestCase
{
    /**
     * Valida uma geração de chave simples.
     * Caso o algoritmo interno rejeite os limites e lance uma exceção, o assert 
     * faz o fallback comparando a string de erro exata com a string da expectativa.
     */
    private function assertKey($a, $b, $exp)
    {
        try {
            $act = FractionalIndexing::generateKeyBetween($a, $b);
        } catch (\Exception $e) {
            $act = $e->getMessage();
        }

        $this->assertEquals($exp, $act, "$exp == $act");
    }

    /**
     * Valida a geração de chaves com heads e alfabetos (fracionários) customizados.
     */
    private function assertIntDigitsKey($digits, $intDigits, $a, $b, $exp)
    {
        try {
            $act = FractionalIndexing::generateKeyBetween($a, $b, $digits, $intDigits);
        } catch (\Exception $e) {
            $act = $e->getMessage();
        }

        $this->assertEquals($exp, $act, "$exp == $act");
    }

    /**
     * Valida a geração iterativa/recursiva de múltiplas chaves num único gap,
     * colapsando o array retornado para string, permitindo comparação rápida.
     */
    private function assertNKeys($a, $b, $n, $exp)
    {
        $BASE_10_DIGITS = '0123456789';
        try {
            $act = implode(' ', FractionalIndexing::generateNKeysBetween($a, $b, $n, $BASE_10_DIGITS));
        } catch (\Exception $e) {
            $act = $e->getMessage();
        }

        $this->assertEquals($exp, $act, "$exp == $act");
    }

    /**
     * Valida a geração com o vasto alfabeto de 95 caracteres estendido.
     */
    private function assertBase95Key($a, $b, $exp)
    {
        $BASE_95_DIGITS = " !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~";
        try {
            $act = FractionalIndexing::generateKeyBetween($a, $b, $BASE_95_DIGITS, FractionalIndexing::BASE_52_DIGITS);
        } catch (\Exception $e) {
            $act = $e->getMessage();
        }

        $this->assertEquals($exp, $act, "$exp == $act");
    }

    /**
     * Valida os erros sintáticos nos arrays usando strings exatas, simulando a quebra de contrato.
     */
    private function assertNDigitsKeys($digits, $a, $b, $n, $exp)
    {
        try {
            $act = implode(' ', FractionalIndexing::generateNKeysBetween($a, $b, $n, $digits));
        } catch (\Exception $e) {
            $act = $e->getMessage();
        }

        $this->assertEquals($exp, $act, "$exp == $act");
    }

    /**
     * Stress Test de ordenação.
     * Simula 1.000 inserções numéricas distribuídas aleatoriamente.
     * Para cada nova inserção, garante rigorosamente que a chave gerada permaneceu logicamente 
     * situada entre o seu anterior e posterior (quando existirem).
     * Por fim, ordena todo o banco (array) criado com ordem lexicográfica, e confirma
     * se a ordem física ordenada bate exatamente com o histórico orgânico de inserções.
     */
    private function assertOrdering($digits, $intDigits = null)
    {
        // Gerador de sequência Pseudo-Aleatória idêntico à implementação do teste original JS,
        // garantindo que os mesmos cenários exatos de limites de bytes ocorram no PHP.
        $seed = 1;
        $rnd = function () use (&$seed) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            return $seed / 0x7fffffff;
        };

        $list = [];
        for ($i = 0; $i < 1000; $i++) {
            $pos = (int) floor($rnd() * (count($list) + 1));
            $a = $pos > 0 ? $list[$pos - 1] : null;
            $b = $pos < count($list) ? $list[$pos] : null;
            
            // Aqui geramos a chave, assumindo que as posições aleatórias estão imprensando o elemento:
            $k = FractionalIndexing::generateKeyBetween($a, $b, $digits, $intDigits);
            
            // PHP loose types string coercing issue prevention: Utiliza-se strcmp ao invés de `<`.
            if ($a !== null) {
                $this->assertTrue(strcmp($a, $k) < 0, "Expected $a < $k");
            }
            if ($b !== null) {
                $this->assertTrue(strcmp($k, $b) < 0, "Expected $k < $b");
            }
            
            array_splice($list, $pos, 0, [$k]);
        }
        
        $sorted = $list;
        sort($sorted, SORT_STRING);
        
        $this->assertEquals($sorted, $list, "List must be strictly sorted");
    }

    // =========================================================================
    // 1. Golden / Reference Tests
    // Validação rígida e byte-for-byte da mesma geração entre originais JS e PHP.
    // =========================================================================
    public function testDefaultKeys()
    {
        // Geração inicial nula - Retorna primeiro bucket médio.
        $this->assertKey(null, null, 'a0');
        // Insere depois e antes sucessivamente.
        $this->assertKey(null, 'a0', 'Zz');
        $this->assertKey(null, 'Zz', 'Zy');
        $this->assertKey('a0', null, 'a1');
        $this->assertKey('a1', null, 'a2');
        // Testes isolados de limites curtos.
        $this->assertKey('a0', 'a1', 'a0V');
        $this->assertKey('a1', 'a2', 'a1V');
        $this->assertKey('a0V', 'a1', 'a0l');
        $this->assertKey('Zz', 'a0', 'ZzV');
        $this->assertKey('Zz', 'a1', 'a0');
        
        // Edge cases testados na implementação originária:
        $this->assertKey(null, 'Y00', 'Xzzz');
        $this->assertKey('bzz', null, 'c000');
        $this->assertKey('a0', 'a0V', 'a0G');
        $this->assertKey('a0', 'a0G', 'a08');
        $this->assertKey('b125', 'b129', 'b127');
        $this->assertKey('a0', 'a1V', 'a1');
        $this->assertKey('Zz', 'a01', 'a0');
        $this->assertKey(null, 'a0V', 'a0');
        $this->assertKey(null, 'b999', 'b99');
        
        // Testes preventivos para chaves corrompidas.
        $this->assertKey(null, 'A00000000000000000000000000', 'invalid order key: A00000000000000000000000000');
        $this->assertKey(null, 'A000000000000000000000000001', 'A000000000000000000000000000V');
        $this->assertKey('zzzzzzzzzzzzzzzzzzzzzzzzzzy', null, 'zzzzzzzzzzzzzzzzzzzzzzzzzzz');
        $this->assertKey('zzzzzzzzzzzzzzzzzzzzzzzzzzz', null, 'zzzzzzzzzzzzzzzzzzzzzzzzzzzV');
        $this->assertKey('a00', null, 'invalid order key: a00');
        $this->assertKey('a00', 'a1', 'invalid order key: a00');
        $this->assertKey('0', '1', 'invalid order key head: 0');
        $this->assertKey('a1', 'a0', 'a0V');
    }

    // =========================================================================
    // 2. Geração em Lote (Lote Múltiplo - NKeysBase)
    // Protege contra desvios de array ou falhas matemáticas na divisão do midpoint recursiva.
    // =========================================================================
    public function testNKeysBase10()
    {
        $this->assertNKeys(null, null, 5, '50 51 52 53 54');
        $this->assertNKeys('54', null, 10, '55 56 57 58 59 600 601 602 603 604');
        $this->assertNKeys(null, '50', 5, '45 46 47 48 49');
        $this->assertNKeys('50', '52', 20, '501 502 503 5035 504 505 506 507 508 509 51 511 512 513 514 515 516 517 518 519');
    }

    // =========================================================================
    // 3. Alfabetos Customizados 
    // Garante que cabeças ou extensões suportam caracteres não-padrão da Base62.
    // =========================================================================
    public function testBase95Keys()
    {
        $this->assertBase95Key('a00', 'a01', 'a00P');
        $this->assertBase95Key('a0/', 'a00', 'a0/P');
        $this->assertBase95Key(null, null, 'a ');
        $this->assertBase95Key('a ', null, 'a!');
        $this->assertBase95Key(null, 'a ', 'Z~');
        $this->assertBase95Key('a0 ', 'a0!', 'invalid order key: a0 ');
        $this->assertBase95Key(null, 'A                          0', 'A                          (');
        $this->assertBase95Key('a~', null, 'b  ');
        $this->assertBase95Key('Z~', null, 'a ');
        $this->assertBase95Key('b   ', null, 'invalid order key: b   ');
        $this->assertBase95Key('a0', 'a0V', 'a0;');
        $this->assertBase95Key('a  1', 'a  2', 'a  1P');
        $this->assertBase95Key(null, 'A                          ', 'invalid order key: A                          ');
    }

    // =========================================================================
    // 4. Validação de Caracteres Restritos (Multi-byte UTF-8, etc)
    // PHP avalia `ord` a nível de byte e não suporta nativamente UTF-16 arrays
    // sem as bibliotecas do MBString, por isso decodificamos a chamada abaixo
    // para espelhar a rejeição de limites de single-byte do JavaScript.
    // =========================================================================
    public function testNDigitsAndErrors()
    {
        // Alfabeto puramente 0 ou 1. (tiny range)
        $this->assertNDigitsKeys('01', null, null, 8, '10 11 111 1111 11111 111111 1111111 11111111');
        $this->assertNDigitsKeys('01', '10', null, 1, '11');
        $this->assertNDigitsKeys('01', '10', '11', 1, '101');
        
        // Multi-byte string falha porque escapa o single-byte format exigido pelas matrizes e cálculos.
        $this->assertNDigitsKeys('ΑΒΓΔΕΖΗΘ', null, null, 10, 'digits must be single-byte (char code 0-255): ΑΒΓΔΕΖΗΘ');
        
        // Strings latin-1 convertidas para o ambiente ISO-8859-1 (representação pura de Bytes no PHP sem multibyte).
        $this->assertNDigitsKeys(utf8_decode('¡¢£¤¥¦'), null, null, 6, utf8_decode('¤¡ ¤¢ ¤£ ¤¤ ¤¥ ¤¦'));
        $this->assertNDigitsKeys(' !#$%&', null, null, 6, '$  $! $# $$ $% $&');
    }

    // =========================================================================
    // 5. Restrições e Comportamento dos "Integer Heads"
    // =========================================================================
    public function testIntDigits()
    {
        $this->assertIntDigitsKey('0123456789', 'ABab', 'a0', 'a1', 'a05');
        $this->assertIntDigitsKey('0123456789', 'ABab', 'a9', null, 'b00');
        $this->assertIntDigitsKey('0123456789', 'ABab', 'b00', null, 'b01');
        $this->assertIntDigitsKey('0123456789', 'ABab', 'a0', null, 'a1');
        $this->assertIntDigitsKey('0123456789', 'ABab', null, 'B9', 'B8');
        
        // Cabeças fora do alfabeto restrito lançam exceções
        $this->assertIntDigitsKey('0123456789', 'ABab', 'c00', null, 'invalid order key head: c');
        $this->assertIntDigitsKey('0123456789', 'ABab', '00', '01', 'invalid order key head: 0');
    }

    public function testIntDigitsFallbackToDigits()
    {
        // Se intDigits (alfabeto inteiro de heads) for fornecido igual ou omitido (caindo pro default), 
        // a biblioteca constrói exatamente com as mesmas regras sem falhar.
        $seed = 1;
        $rnd = function () use (&$seed) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            return $seed / 0x7fffffff;
        };
        $digits = '0123456789';
        $list = [];
        for ($i = 0; $i < 2000; $i++) {
            $pos = (int) floor($rnd() * (count($list) + 1));
            $a = $pos > 0 ? $list[$pos - 1] : null;
            $b = $pos < count($list) ? $list[$pos] : null;
            $def = FractionalIndexing::generateKeyBetween($a, $b, $digits);
            $cust = FractionalIndexing::generateKeyBetween($a, $b, $digits, $digits);
            $this->assertEquals($def, $cust);
            array_splice($list, $pos, 0, [$def]);
        }
    }

    public function testIntDigitsSameAsDigits()
    {
        $this->assertIntDigitsKey('0123456789', '0123456789', null, null, '50');
        $this->assertIntDigitsKey('0123456789', '0123456789', '50', null, '51');
        $this->assertIntDigitsKey('0123456789', '0123456789', '59', null, '600');
        $this->assertIntDigitsKey('0123456789', '0123456789', null, '50', '49');
        $this->assertIntDigitsKey('0123456789', '0123456789', '56', '57', '565');
    }

    public function testValidationErrors()
    {
        // Regras formais: o alfabeto não pode ser bagunçado, ter números duplicados e heads devem ser de número par.
        $this->assertIntDigitsKey('0213456789', 'ABab', null, null, 'digits must be at least 2 characters in strictly ascending character code order: 0213456789');
        $this->assertIntDigitsKey('0', 'ABab', null, null, 'digits must be at least 2 characters in strictly ascending character code order: 0');
        $this->assertIntDigitsKey('0012', 'ABab', null, null, 'digits must be at least 2 characters in strictly ascending character code order: 0012');
        
        $this->assertIntDigitsKey('0123456789', 'abc', null, null, 'intDigits must be an even number of at least 2 characters in strictly ascending character code order: abc');
        $this->assertIntDigitsKey('0123456789', 'ba', null, null, 'intDigits must be an even number of at least 2 characters in strictly ascending character code order: ba');
        $this->assertIntDigitsKey('0123456789', '', null, null, 'intDigits must be an even number of at least 2 characters in strictly ascending character code order: ');
        
        $this->assertIntDigitsKey('0123456789', 'ΑΒΓΔ', null, null, 'intDigits must be single-byte (char code 0-255): ΑΒΓΔ');
        $this->assertNDigitsKeys('0', null, null, 5, 'digits must be at least 2 characters in strictly ascending character code order: 0');
    }

    public function testNegativeN()
    {
        $testNegativeNFunc = function ($a, $b) {
            try {
                $act = implode(' ', FractionalIndexing::generateNKeysBetween($a, $b, -1));
            } catch (\Exception $e) {
                $act = $e->getMessage();
            }
            $this->assertEquals('n must be >= 0: -1', $act);
        };

        $testNegativeNFunc(null, null);
        $testNegativeNFunc('a0', null);
        $testNegativeNFunc(null, 'a1');
        $testNegativeNFunc('a0', 'a5');
    }

    // =========================================================================
    // 6. Stress Tests de Ordenação com Multi-Alfabetos
    // Confirma durabilidade e estabilidade se aplicados milhares de inserções e reordenações massivas.
    // =========================================================================
    public function testStressOrdering()
    {
        $this->assertOrdering('0123456789');
        $this->assertOrdering(' !#$%&');
        $this->assertOrdering(utf8_decode('¡¢£¤¥¦'));
        $this->assertOrdering(FractionalIndexing::BASE_62_DIGITS);
        // Base 2 self-heading has too small an integer range to sustain 1000 inserts,
        // so we widen it using the broad A-Z/a-z heads.
        $this->assertOrdering('01', FractionalIndexing::BASE_52_DIGITS);
        $this->assertOrdering('0123456789', '0123456789');
    }
}
