# Binance PHP Trader

Experimental bot for auto trading on the Binance.com exchange written in PHP with Tradingview Technical analysis and Telegram notifications.


# Requirements

  - PHP
  - PHP Curl
  - Composer
  - GIT


# Installation

### Requirements (Ubuntu)

```sh
$ sudo apt-get update & apt-get upgrade
$ sudo apt-get install git php php-curl
$ wget https://raw.githubusercontent.com/composer/getcomposer.org/76a7060ccb93902cd7576b67264ad91c8a2700e2/web/installer -O - -q | php -- --quiet
```


### Binance PHP Trader

```sh
$ git clone https://github.com/andrejtrcek/binance-php-trader.git
$ cd binance-php-trader
$ composer install
```


# Configuration

1. Signup for [Binance](https://www.binance.com/en/register?ref=T0J9L2XU)
2. Enable Two-factor Authentication
3. Go API Center and [Create a New Api Key](https://www.binance.com/en/my/settings/api-management?ref=T0J9L2XU)
4. Modify configuration parameters inside **trader.php**


### Configuration parameters

### Required
**primary**: The cryptocurrency Trader buys (ex. BTC, ETH, LTC, ...)

**secondary**: The cryptocurrency Trader sells (ex. BTC, USDT, ...)

**binance/key**: Binance API key

**binance/key**: Binance API secret

**digits/price**: Number of digits for price determined by the exchange (ex. ETH: price = 2)

**digits/quantity**: Number of digits for quantity determined by the exchange (ex. ETH: quantity = 5)


### Optional
**tradingview/period/buy**: Tradingview period to fetch for a BUY order (ex. 1, 5, 15, 60, ...)

**tradingview/period/sell**: Tradingview period to fetch for a SELL order (ex. 1, 5, 15, 60, ...)

**tradingview/url**: Tradingview URL Endpoint

**tradingview/margin**: Determine if the currency is for buying or selling (strong sell = <-0.5, sell = -0.5-0, buy = 0 - 0.5, strong buy = 0.5>)

**telegram/token**: Telegram bot token for notifitations (if empty, Telegram bot does not work)

**telegram/token**: Telegram Channel ID

**trendNum/buy**: Number of trades to count as trend for a BUY order

**trendNum/sell**: Number of trades to count as trend for a SELL order

**tradeMin**: Min. amount of secondary currency determined by the exchange to trade

**tradePercentage**: Amount (in percentage) of secondary cryptocurrency to trade

**tradePrice**: Trade price difference from market price (in percentage)

**orderRepeat**: Max. amount of cycles per single buy/sell order

**stop/loss**: Max. loss in percentage

**stop/gain**: If price starts dropping, sell only if min. this much gain (in percentage)

**sleepTime**: Time (seconds) between two loops


### Usage

```sh
$ php trader.php
```


### DISCLAIMER

I am not responsible for anything done with this bot. You use it at your own risk. There are no warranties or guarantees expressed or implied. You assume all responsibility and liability.


# Author

Andrej Trcek

Web: http://www.andrejtrcek.com

E-mail: me@andrejtrcek.com


# License
Code released under the MIT License.
