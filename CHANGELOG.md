# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-08-31
### Added
- Implementação inicial da biblioteca.
- Suporte para PHP >= 7.1.
- Função `generateKeyBetween` para gerar uma chave intermediária.
- Função `generateNKeysBetween` para gerar múltiplas chaves intermediárias em lote.
- Testes de compatibilidade garantindo paridade semântica (Golden e Stress tests) com a implementação `rocicorp/fractional-indexing`.
- Zero dependências em runtime, garantindo flexibilidade e leveza para consumo.
