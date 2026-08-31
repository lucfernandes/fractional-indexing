# Fractional Indexing PHP

Compatível com os casos de referência e com a semântica do [rocicorp/fractional-indexing](https://github.com/rocicorp/fractional-indexing).

Fractional indexing é uma técnica para gerar chaves de ordenação que permitem inserir itens em qualquer posição de uma lista sem precisar reordenar os itens adjacentes.

## Diferenciais

- **PHP >= 7.1**: Suporte desde o PHP 7.1 até as versões mais modernas (8.x).
- **Zero runtime dependencies**: Nenhuma dependência externa necessária para rodar em produção.
- **Port fiel**: Port da implementação original da Rocicorp, garantindo os mesmos conceitos e semântica.
- **PSR-4**: Código estruturado sob o padrão PSR-4.
- **Framework agnostic**: Pode ser utilizado em qualquer framework ou projeto PHP puro.
- **Base62 padrão e Customização**: Foco inicial no uso do alfabeto Base62 (`0-9A-Za-z`) como *default ordering*, mas suporta integralmente os argumentos customizados de `digits` e `intDigits` assim como a implementação upstream original.
- **Geração simples**: Suporte a `generateKeyBetween`.
- **Geração em lote**: Suporte a `generateNKeysBetween`.

## Instalação

```bash
composer require lucas-fernandes/fractional-indexing
```

## Quick Start

```php
use FractionalIndexing\FractionalIndexing;

// Gerar a primeira chave
$firstKey = FractionalIndexing::generateKeyBetween(null, null); // "a0"

// Inserir depois
$secondKey = FractionalIndexing::generateKeyBetween($firstKey, null); // "a1"

// Inserir antes
$zerothKey = FractionalIndexing::generateKeyBetween(null, $firstKey); // "Zz"

// Inserir no meio
$midKey = FractionalIndexing::generateKeyBetween($firstKey, $secondKey); // "a0V"
```

## Geração em Lote

Para inserir múltiplos itens de uma vez entre dois elementos:

```php
$keys = FractionalIndexing::generateNKeysBetween('a0', 'a1', 3);
// ['a0G', 'a0V', 'a0l']
```

## Banco de Dados & Ordenação

As chaves são geradas para serem ordenadas **lexicograficamente**.
Se armazenadas em banco (ex: MySQL, Postgres), a coluna precisa ter uma **collation case-sensitive** (ex: `utf8mb4_bin`).

> **Nota:** Não existe limite rígido de caracteres na geração da chave. É responsabilidade da aplicação limitar na coluna caso a string cresça muito após milhares de inserções consecutivas no mesmo ponto.

## Testes

A biblioteca cobre os vetores de teste (Golden Tests e Stress Tests) portados da implementação original para atestar paridade semântica. 
A compatibilidade byte-for-byte poderá ser declarada quando houver verificação automatizada comparando o binário original em JS com os outputs do PHP.

```bash
composer install
./vendor/bin/phpunit
```

## Créditos

Inspirado e portado a partir da biblioteca de [David Greenspan / Rocicorp (CC0)](https://github.com/rocicorp/fractional-indexing).

## Licença

[MIT](LICENSE).
