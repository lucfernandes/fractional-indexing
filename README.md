# Fractional Indexing PHP

Compatível com os casos de referência e com a semântica do [rocicorp/fractional-indexing](https://github.com/rocicorp/fractional-indexing).

O **Fractional Indexing** é uma técnica matemática e algorítmica utilizada para gerar chaves de ordenação (order keys) lexicográficas.

### Qual problema isso resolve?
Em sistemas tradicionais, quando você possui uma lista ordenada (ex: Kanban board, lista de tarefas) e o usuário move um item da posição 10 para a posição 2, o sistema precisa atualizar a coluna "order" do item movido e de todos os outros itens adjacentes que foram empurrados para baixo.
Isso causa overhead intenso no banco de dados e dificuldade absurda em ambientes de concorrência ou sincronização offline-first.

Com o Fractional Indexing, a chave gerada possui um comprimento variável. Ao invés de reordenar toda a lista, a técnica apenas "calcula o ponto médio" entre as chaves da posição 1 e da posição 3. Resultado: **apenas 1 item é atualizado no banco de dados**.

### Diferença para o LexoRank
O Jira popularizou o algoritmo **LexoRank**, que também gera chaves lexicográficas. No entanto, o LexoRank geralmente sofre com limitação de tamanho estático, necessitando de um sistema complexo de *buckets* e processos de *rebalanceamento* periódico quando as chaves se esgotam ou ficam muito densas. 
O *Fractional Indexing* resolve esse problema permitindo strings de comprimento dinâmico que podem (teoricamente) crescer infinitamente sem a necessidade de rebalanceamento, simplificando drasticamente o backend.

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

## Quick Start (generateKeyBetween)

A função base permite gerar chaves passando os limites anterior e posterior. 
O valor `null` é usado para indicar os extremos (início ou fim da lista).

```php
use FractionalIndexing\FractionalIndexing;

// Inserir o primeiro item da lista inteira
$firstKey = FractionalIndexing::generateKeyBetween(null, null); // "a0"

// Inserir um item APÓS o primeiro (anexar ao fim)
$secondKey = FractionalIndexing::generateKeyBetween($firstKey, null); // "a1"

// Inserir um item ANTES do primeiro (anexar ao início)
$zerothKey = FractionalIndexing::generateKeyBetween(null, $firstKey); // "Zz"

// Inserir um item NO MEIO de dois elementos
$midKey = FractionalIndexing::generateKeyBetween($firstKey, $secondKey); // "a0V"
```

## Geração em Lote (generateNKeysBetween)

Para inserir múltiplos itens de uma vez (por exemplo, ao arrastar um bloco inteiro de elementos para o meio de outros dois):

```php
$keys = FractionalIndexing::generateNKeysBetween('a0', 'a1', 3);
// Retorna: ['a0G', 'a0V', 'a0l']
```

## Banco de Dados & Ordenação

As chaves são geradas para serem ordenadas **lexicograficamente**.
Se armazenadas em banco (ex: MySQL, Postgres), a coluna precisa ter uma **collation case-sensitive** (ex: `utf8mb4_bin`), caso contrário, `a0` e `A0` seriam tratados como idênticos e causariam falha na ordenação.

Exemplo de schema MySQL:
```sql
CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    order_key VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    INDEX (order_key)
);
```

### Limitações Conhecidas

> **Nota de Crescimento:** O Fractional Indexing não impõe limite de tamanho na geração das chaves. Quanto mais inserções "midpoint" (inserir repetidamente entre duas chaves existentes super densas) ocorrem, maior a string fica. É responsabilidade da aplicação consumidora lidar com os limites da coluna do banco de dados (ex: `VARCHAR(255)`) e tratar o caso improvável em que o limite de armazenamento seja atingido após milhares de inserções consecutivas no mesmo espaço reduzido.

## Testes e Tratamento de Erros

A biblioteca lança `FractionalIndexing\Exception\FractionalIndexingException` caso parâmetros inválidos ou fora de escopo sejam recebidos.

A suíte de testes cobre todos os vetores de teste portados da implementação original para atestar paridade semântica (Golden e Stress Tests). A compatibilidade *byte-for-byte* poderá ser declarada quando houver verificação automatizada via CI comparando o binário original em JS com os outputs do PHP.

Para rodar os testes localmente:
```bash
composer install
./vendor/bin/phpunit
```

## Créditos

Inspirado e portado a partir da biblioteca de [David Greenspan / Rocicorp (CC0)](https://github.com/rocicorp/fractional-indexing).

## Licença

[MIT](LICENSE).
