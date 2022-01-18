# BitBot

The code here is my unfinished crypto currency trading bot. Written in php using two libraries cctx and textalk/websocket.
I modified websocket to make it asynchronous.

At the time of writing it recorded all trades on bitfinex and bitstamp along with hourly tickers on any defined crypto
pair.The analyser is incomplete and there is no buy sell function that would be triggered by analysis.

It has a rudimentary web page frontend and backend is mysql, an export of which can be found in database.zip