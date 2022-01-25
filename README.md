# BitBot

The code here is my unfinished crypto currency trading bot. Written in php using two libraries cctx/cctx and
textalk/websocket I modified websocket to make it asynchronous.

At the time of writing it recorded all trades on bitfinex and bitstamp along with hourly tickers on any defined crypto
pair.The analyser is incomplete as is the buy sell  function that would be triggered by analysis... if implemented.

It has a rudimentary web page frontend and backend is mysql, an export of which can be found in bitbot.zip

Designed to run on a remote web server, it uses my simple templating engine to produce web pages and uses a pseudo
thread class to replicate different asynchronous threads.    

I abandoned the project as I became too addicted to watching the crypto prices, it was having a negative impact on my
life.

## Setup

If you want to see this code going you will need to import the database extract "bitbox.zip" into a locally hosted mysql
database.

Edit inc/db_account.php to reflect a username and password of an account with access to the database. My extact has the
database name of "bitbot".

Create an api key on Bitfinex and enter it with its secret into the database on the accounts table.

Run bit_bot.php using php CLI. This will start the three threads "scribe" - recorder of all trades and tickers,
"analyser" - code to pass tickers and trades to create alerts and weighted buy sell suggestions to "trader" that would
buy and sell based on defined parameters.     