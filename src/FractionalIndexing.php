<?php

namespace LucFernandes\FractionalIndexing;

use LucFernandes\FractionalIndexing\Exception\FractionalIndexingException;

/**
 * Biblioteca para geração de chaves de ordenação (order keys) utilizando Fractional Indexing.
 *
 * Fractional Indexing resolve o problema de ordenação em listas permitindo gerar chaves 
 * de comprimento dinâmico que se encaixam exatamente entre outras duas chaves conhecidas.
 * Isso evita a necessidade de reordenar o banco de dados inteiro ao mover um único item,
 * algo muito comum em boards Kanban ou listas drag-and-drop.
 * 
 * As chaves geradas consistem numa "parte inteira" e uma "parte fracionária".
 * A parte inteira define a magnitude (comprimento) do valor para garantir correta
 * ordenação lexicográfica de chaves de tamanhos diferentes, prefixada por um caractere ("head").
 */
class FractionalIndexing
{
    /**
     * Alfabeto Base62 (0-9, A-Z, a-z). 
     * Usado por padrão para a parte fracionária, compondo os valores reais da chave.
     */
    public const BASE_62_DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; // 0-9 + A-Z + a-z

    /**
     * Alfabeto Base52 (A-Z, a-z).
     * Usado por padrão para compor o "head" (parte inteira), para que as chaves resultantes
     * mantenham a aparência clássica de letras seguidas de números (ex: "a0", "Zz").
     */
    public const BASE_52_DIGITS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; // A-Z + a-z

    /** 
     * Cache estático mapeando a string do alfabeto para um mapa de índice.
     * Exemplo: ['0123...' => [48 => 0, 49 => 1, ...]] onde as chaves são o `ord()` do char.
     * Isso substitui O(N) do strpos() por acessos em O(1), otimizando a geração massiva.
     * 
     * @var array<string, array<int, int>> 
     */
    private static $digitIndexCache = [];

    /**
     * Cache das menores chaves geráveis para cada alfabeto "head" fornecido.
     * Evita recriar a mesma string longa repetidamente, aliviando o Garbage Collector.
     * 
     * @var array<string, array<int, string>> 
     */
    private static $repeatedKeysCache = [];

    /** 
     * Armazena alfabetos de dígitos (fracionários) que já foram validados.
     * Evita rodar a validação de formato e single-byte a cada chamada.
     * 
     * @var array<string, bool> 
     */
    private static $validatedDigits = [];

    /** 
     * Armazena alfabetos de dígitos inteiros ("heads") que já foram validados.
     * @var array<string, bool> 
     */
    private static $validatedIntDigits = [];

    /**
     * Retorna o lookup table que traduz o código ASCII do caractere para seu valor/índice.
     *
     * @param string $digits Alfabeto.
     * @return array<int, int> Mapa onde a chave é ord(char) e o valor é o índice numérico (0 a N).
     */
    private static function getDigitIndex($digits)
    {
        if (!isset(self::$digitIndexCache[$digits])) {
            $m = [];
            $len = strlen($digits);
            // Mapeia byte a byte do alfabeto.
            for ($i = 0; $i < $len; $i++) {
                $m[ord($digits[$i])] = $i;
            }
            self::$digitIndexCache[$digits] = $m;
        }
        return self::$digitIndexCache[$digits];
    }

    /**
     * Calcula o caractere/string que representa o ponto médio exato entre duas strings fornecidas.
     * Baseia-se no algoritmo da Rocicorp onde as strings podem ser vazias ou parciais.
     *
     * @param string $a A string base menor.
     * @param string|null $b A string limite maior, ou null se não houver limite superior.
     * @param string $digits O alfabeto fracionário disponível.
     * @param array<int, int> $lookup Lookup table do alfabeto.
     * @return string O valor correspondente ao meio das duas strings.
     * @throws FractionalIndexingException
     */
    private static function midpoint($a, $b, $digits, $lookup)
    {
        $a = (string) $a;
        if ($b !== null) {
            $b = (string) $b;
        }
        
        $zero = $digits[0];
        // Nota JS x PHP: strcmp é mandatório para evitar coerção implícita (ex: "495" < "50" é int 495 < 50 => false)
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
            
            // Remove o prefixo em comum entre $a e $b. 
            // Preenche $a com "zeros" lógicos caso seja menor.
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
        
        // Os primeiros dígitos divergem (ou $b tem o primeiro dígito maior).
        $digitA = ($a !== '') ? (isset($lookup[ord($a[0])]) ? $lookup[ord($a[0])] : 0) : 0;
        $digitB = ($b !== null && $b !== '') ? (isset($lookup[ord($b[0])]) ? $lookup[ord($b[0])] : strlen($digits)) : strlen($digits);

        // Se há espaço de sobra entre o primeiro digito de $a e $b, pegamos um dígito bem no meio.
        if ($digitB - $digitA > 1) {
            $midDigit = (int) round(0.5 * ($digitA + $digitB));
            return $digits[$midDigit];
        } else {
            // Os dígitos são consecutivos. Precisamos gerar um digito adicional para criar espaço.
            if ($b !== null && strlen($b) > 1) {
                return substr($b, 0, 1);
            } else {
                // b é null ou length 1. Concatenamos e chamamos recursivamente para estender a fração.
                return $digits[$digitA] . self::midpoint(substr($a, 1), null, $digits, $lookup);
            }
        }
    }

    /**
     * Calcula o comprimento da parte inteira da chave a partir de seu caractere inicial (o "head").
     * Em Fractional Indexing, a primeira letra dita quantos caracteres subseqüentes pertencem à parte inteira
     * (e em qual direção magnética cresce).
     *
     * @param string $head O primeiro caractere da chave de ordem.
     * @param string $intDigits O alfabeto reservado para os heads da parte inteira.
     * @param array<int, int> $intLookup Tabela mapeando heads para seus índices.
     * @return int O número total de caracteres (incluindo o head) da parte inteira.
     * @throws FractionalIndexingException Se a chave iniciar com um head não cadastrado no intDigits.
     */
    private static function getIntegerLength($head, $intDigits, $intLookup)
    {
        $headOrd = ord($head[0]);
        if (isset($intLookup[$headOrd])) {
            $i = $intLookup[$headOrd];
            if ($intDigits[$i] === $head) {
                $half = strlen($intDigits) / 2;
                // A primeira metade dos caracteres denota valores negativos (heads diminuindo de tamanho lógico),
                // e a segunda metade denota valores positivos (heads aumentando).
                return $i < $half ? (int) ($half - $i + 1) : (int) ($i - $half + 2);
            }
        }
        throw new FractionalIndexingException('invalid order key head: ' . $head);
    }

    /**
     * Valida se uma string fornecida corresponde exata e unicamente à parte inteira esperada pelo seu próprio head.
     *
     * @param string $int A parte inteira extraída ou gerada.
     * @param string $intDigits O alfabeto de heads.
     * @param array<int, int> $intLookup Tabela dos heads.
     * @throws FractionalIndexingException
     */
    private static function validateInteger($int, $intDigits, $intLookup)
    {
        if (strlen($int) !== self::getIntegerLength($int[0], $intDigits, $intLookup)) {
            throw new FractionalIndexingException('invalid integer part of order key: ' . $int);
        }
    }

    /**
     * Extrai somente o prefixo "inteiro" de uma order key (removendo a parte fracionária).
     *
     * @param string $key A chave completa.
     * @param string $intDigits Alfabeto de heads.
     * @param array<int, int> $intLookup
     * @return string O substring correspondente à parte inteira.
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
     * Efetua a validação formal e estrutural de uma order key.
     * Garante que não é o limite inferior mínimo possível nem possui trailings nulos.
     *
     * @param string $key A chave.
     * @param string $digits Alfabeto.
     * @param string $intDigits Alfabeto de heads.
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
        // A parte fracionária não pode terminar com o menor valor do alfabeto (equivalente ao 0), 
        // pois isso criaria chaves redundantes e equivalentes.
        if (substr($f, -1) === $digits[0]) {
            throw new FractionalIndexingException('invalid order key: ' . $key);
        }
    }

    /**
     * Incrementa a parte inteira alfanumérica de forma segura.
     * Transita e carrega "vai um" (carry over) entre as magnitudes se os dígitos esgotarem.
     *
     * @param string $x A string inteira.
     * @param string $digits
     * @param array<int, int> $lookup
     * @param string $intDigits
     * @param array<int, int> $intLookup
     * @return string|null Nova parte inteira, ou null se atingiu o valor máximo do alfabeto.
     * @throws FractionalIndexingException
     */
    private static function incrementInteger($x, $digits, $lookup, $intDigits, $intLookup)
    {
        self::validateInteger($x, $intDigits, $intLookup);
        $head = $x[0];
        $zero = $digits[0];
        $trailing = '';
        
        // Caminha pelos dígitos do fim pro começo transformando máximos em zeros e subindo a casa decimal (carry out).
        for ($i = strlen($x) - 1; $i >= 1; $i--) {
            $charOrd = ord($x[$i]);
            $d = (isset($lookup[$charOrd]) ? $lookup[$charOrd] : 0) + 1;
            if ($d === strlen($digits)) {
                $trailing = $zero . $trailing;
            } else {
                return $head . substr($x, 1, $i - 1) . $digits[$d] . $trailing;
            }
        }
        
        // Se vazou da string, precisamos aumentar o "head" (trocando para o próximo na escala de heads).
        $headIndex = $intLookup[ord($head[0])];
        if ($headIndex === strlen($intDigits) - 1) {
            return null; // O algoritmo esgotou o limite superior suportado pelos alfabetos atuais.
        }
        
        $h = $intDigits[$headIndex + 1];
        $lengthDelta = self::getIntegerLength($h, $intDigits, $intLookup) - self::getIntegerLength($head, $intDigits, $intLookup);
        
        return $h . ($lengthDelta > 0 ? $trailing . $zero : ($lengthDelta < 0 ? substr($trailing, 1) : $trailing));
    }

    /**
     * Decrementa a parte inteira alfanumérica, tratando "empréstimos" de casas decimais.
     * Funciona como a subtração de um hodômetro de carros, alterando a magnitude (head) 
     * ao esgotar os números abaixo.
     *
     * @param string $x A string inteira.
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
            return null; // Alcançou a menor string possível.
        }
        
        $h = $intDigits[$headIndex - 1];
        $lengthDelta = self::getIntegerLength($h, $intDigits, $intLookup) - self::getIntegerLength($head, $intDigits, $intLookup);
        
        return $h . ($lengthDelta > 0 ? $trailing . $last : ($lengthDelta < 0 ? substr($trailing, 1) : $trailing));
    }

    /**
     * Verifica se a string passada é o limite extremo inferior do algoritmo (Smallest Integer possível).
     *
     * @param string $key A chave.
     * @param string $digits Alfabeto.
     * @param string $intDigits Alfabeto dos heads.
     * @return bool
     */
    private static function isSmallestInteger($key, $digits, $intDigits)
    {
        if (!isset(self::$repeatedKeysCache[$intDigits])) {
            self::$repeatedKeysCache[$intDigits] = [];
        }
        $zeroCode = ord($digits[0]);
        if (!isset(self::$repeatedKeysCache[$intDigits][$zeroCode])) {
            // O menor valor absoluto é o head negativo mais profundo, seguido por zeros até compor o tamanho exigido.
            self::$repeatedKeysCache[$intDigits][$zeroCode] = $intDigits[0] . str_repeat($digits[0], (int) (strlen($intDigits) / 2));
        }
        return $key === self::$repeatedKeysCache[$intDigits][$zeroCode];
    }

    /**
     * Valida se os bytes de uma string seguem um valor estritamente ascendente ASCII.
     * Requisito obrigatório para garantir que a ordenação nativa não seja comprometida.
     *
     * @param string $s Alfabeto.
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
     * Valida se uma string enviada como alfabeto é puramente Single-Byte.
     * A biblioteca JS original usa tabelas de tamanho 256. Caracteres multibyte (UTF-8) 
     * rompem essa garantia e devem ser rejeitados antecipadamente, por isso essa validação
     * via Regex detecta se a string possui bytes do padrão UTF-8 fora do range tradicional Single-Byte.
     *
     * @param string $s Alfabeto.
     * @return bool
     */
    private static function isSingleByte($s)
    {
        return !preg_match('/[\xC0-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF7][\x80-\xBF]{3}/', $s);
    }

    /**
     * Valida as restrições arquiteturais para o Alfabeto Fracionário.
     *
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
     * Valida as restrições arquiteturais para o Alfabeto de Heads Inteiros.
     * O comprimento sempre deve ser par (metade cresce negativo, metade cresce positivo).
     *
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
     * A função principal da biblioteca.
     * Gera uma nova string (chave) que lexicalmente estará sempre alocada rigorosamente 
     * entre as chaves $a e $b.
     * 
     * Se $a ou $b forem `null`, representa anexar itens no início infinito ($a nulo) 
     * ou final infinito ($b nulo) da coleção lógica de itens.
     *
     * @param string|null $a Chave de ordenação anterior (ou null se for o início absoluto).
     * @param string|null $b Chave de ordenação posterior (ou null se for o final absoluto).
     * @param string|null $digits Alfabeto customizado. Se nulo, usará BASE_62_DIGITS.
     * @param string|null $intDigits Alfabeto de heads customizados. Se nulo, usará BASE_52_DIGITS (ou igual a $digits).
     * @return string A nova chave de ordenação.
     * @throws FractionalIndexingException Se as chaves passadas estiverem fora dos padrões válidos.
     */
    public static function generateKeyBetween($a, $b, $digits = null, $intDigits = null)
    {
        // 1. Resolução e validação de alfabetos e defaults.
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
        
        // 2. Validação profunda dos inputs
        if ($a !== null) {
            self::validateOrderKey($a, $digits, $intDigits, $intLookup);
        }
        if ($b !== null) {
            self::validateOrderKey($b, $digits, $intDigits, $intLookup);
        }
        
        // 3. Facilidade: se enviados fora de ordem (A maior que B), invertemos para o processamento não explodir.
        if ($a !== null && $b !== null) {
            // Nota PHP: O uso da função strcmp é crucial para comparação correta entre strings numéricas!
            if (strcmp($a, $b) > 0) {
                $temp = $a;
                $a = $b;
                $b = $temp;
            }
        }

        // Caso base: Ambos os lados infinitos (o primeiro elemento de todos sendo criado).
        if ($a === null) {
            if ($b === null) {
                // Seleciona a raiz da árvore de heads, tipicamente 'a0'.
                $head = $intDigits[(int) (strlen($intDigits) / 2)];
                return $head . $digits[0];
            }

            // A=nulo, mas B existe (inserindo antes do limite inferior conhecido).
            $ib = self::getIntegerPart($b, $intDigits, $intLookup);
            $fb = substr($b, strlen($ib));
            if (self::isSmallestInteger($ib, $digits, $intDigits)) {
                return $ib . self::midpoint('', $fb, $digits, $lookup);
            }
            if (strcmp($ib, $b) < 0) {
                return $ib;
            }
            // Precisamos crescer "para a esquerda", subtraindo da parte inteira.
            $res = self::decrementInteger($ib, $digits, $lookup, $intDigits, $intLookup);
            if ($res === null) {
                throw new FractionalIndexingException('cannot decrement any more');
            }
            return $res;
        }

        // B=nulo, mas A existe (inserindo depois do limite superior conhecido, "append").
        if ($b === null) {
            $ia = self::getIntegerPart($a, $intDigits, $intLookup);
            $fa = substr($a, strlen($ia));
            $i = self::incrementInteger($ia, $digits, $lookup, $intDigits, $intLookup);
            return $i === null ? $ia . self::midpoint($fa, null, $digits, $lookup) : $i;
        }

        // Caso normal: Inserindo no meio exato entre A e B que já existem e são próximos.
        $ia = self::getIntegerPart($a, $intDigits, $intLookup);
        $fa = substr($a, strlen($ia));
        $ib = self::getIntegerPart($b, $intDigits, $intLookup);
        $fb = substr($b, strlen($ib));
        
        // Se a parte inteira de ambos é idêntica, trabalhamos os décimos/fração baseando no ponto médio real.
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
     * Permite gerar múltiplas (N) chaves de forma sequencial ou distribuída.
     * Quando $a e $b existem, as chaves são extraídas com uma lógica de divisão paralela e recursiva,
     * garantindo distribuição harmoniosa de espaços.
     *
     * @param string|null $a Limite anterior.
     * @param string|null $b Limite posterior.
     * @param int $n Quantas chaves serão geradas.
     * @param string|null $digits Alfabeto.
     * @param string|null $intDigits Alfabeto de heads.
     * @return string[] Array contendo as $n chaves na ordem gerada (lexicograficamente ordenada).
     * @throws FractionalIndexingException Se $n for negativo ou as chaves forem inválidas.
     */
    public static function generateNKeysBetween($a, $b, $n, $digits = null, $intDigits = null)
    {
        // 1. Validando alfabetos antecipadamente (senão cada iteração executaria uma nova regex)
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
        
        // Estratégias de geração: 
        // Quando um limite não existe (append ou prepend infinito), não usamos distribuição central, 
        // mas sim geração contígua iterativa rápida (uma chave gera a próxima sucessivamente).
        
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
            // Precisamos entregar em ordem cronológica de ordenação.
            return array_reverse($result);
        }
        
        // Caso normal (inserção de multiplas chaves imprensadas entre $a e $b):
        // Cria uma chave central ($c) dividindo o esforço; delega a metade esquerda e a metade direita em recursões.
        $mid = (int) floor($n / 2);
        $c = self::generateKeyBetween($a, $b, $digits, $intDigits);
        
        $left = self::generateNKeysBetween($a, $c, $mid, $digits, $intDigits);
        $right = self::generateNKeysBetween($c, $b, $n - $mid - 1, $digits, $intDigits);
        
        return array_merge($left, [$c], $right);
    }
}
