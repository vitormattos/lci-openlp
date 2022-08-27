# Importador do LCI a partir do site luteranos.com.br

Importador do LCI (Livro de Canto da IECLB) a partir do site da IECLB (Igreja Evangélica de Confissão Luterana no Brasil) para o [OpenLP](https://openlp.org).

## Cenário

Os hinos da Igreja Luterana estão todos nesta página https://www.luteranos.com.br/conteudo/livro-de-canto-da-ieclb-por-numeracao e não há uma forma de baixá-los para serem importados em algum sistema de gestão de liturgia como o OpenLP por exemplo.

Este projeto tem por objetivo solicionar esta quesetão e baixar todos os hinos do LCI para uma base de dados SQLite no padrão do OpenLP e faz isto utilizando-se de web scraping e tratamento dos dados de todas as páginas listadas na URL acima.

## Como usar

O projeto é em PHP e basta executar o script index.php para ter a base de dados em formato SQLite. Vai demorar um pouco para processar tudo por questões óbvias, são mais de 600 hinos e consequentemente mais de 600 páginas para serem acessadas mas no final dá certo :-)
