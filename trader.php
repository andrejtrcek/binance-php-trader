#!/usr/bin/php
<?php

chdir(dirname(__FILE__));

ini_set("error_log", "/tmp/php-error.log");

require 'vendor/autoload.php';

use GuzzleHttp\Exception\ClientException;
use \unreal4u\TelegramAPI\TgLog;
use \unreal4u\TelegramAPI\Telegram\Methods\SendMessage;

class BinanceTrader
{
	var $vars;
	var $api;
	
	function __construct($vars = array()) {
		$this->vars = array_merge(
			array(
				'primary' => 'XXXXX', // The cryptocurrency Trader buys (ex. BTC, ETH, LTC, ...)
				'secondary' => 'XXXXX', // The cryptocurrency Trader sells (ex. BTC, USDT, ...)
				'binance' => array(		'key' => '', // Binance API key
										'secret' => ''), // Binance API secret
				'digits' => array(		'price' => 2, // Number of digits for price determined by the exchange (ex. ETH: price = 2)
										'quantity' => 5), // Number of digits for quantity determined by the exchange (ex. ETH: quantity = 5)
				'tradingview' => array(	'period' => array(	'buy' => 5, // Tradingview period to fetch for a BUY order (ex. 1, 5, 15, 60, ...)
															'sell' => 5), // Tradingview period to fetch for a SELL order (ex. 1, 5, 15, 60, ...)
										'url' => 'https://scanner.tradingview.com/crypto/scan', // Tradingview URL Endpoint
										'margin' => 0.3), // Determine if the currency is for buying or selling (strong sell = <-0.5, sell = -0.5-0, buy = 0 - 0.5, strong buy = 0.5>)
				'telegram' => array(	'token' => '', // Telegram bot token for notifitations (if empty, Telegram bot does not work)
										'channel' => ''), // Telegram Channel ID
				'trendNum' => array(	'buy' => 60, // // Number of trades to count as trend for a BUY order
										'sell' => 60), // Number of trades to count as trend for a SELL order
				'tradeMin' => 10, // Min. amount of secondary currency determined by the exchange to trade
				'tradePercentage' => 80, // Amount (in percentage) of secondary cryptocurrency to trade
				'tradePrice' => 0.01, // Trade price difference from market price (in percentage)
				'orderRepeat' => 3, // Max. amount of cycles per single buy/sell order
				'stop' => array(	'loss' => 2, // Max. loss in percentage
									'gain' => 1), // If price starts dropping, sell only if min. this much gain (in percentage)
				'sleepTime' => 5 // Time (seconds) between two loops
			),
			$vars
		);
		$this->vars['symbol'] = $this->vars['primary'] . $this->vars['secondary'];
		$this->api = new Binance\API($this->vars['binance']['key'], $this->vars['binance']['secret']);
	}

    public function trade() {
		while(true) {

			if (isset($this->vars['status'])) { unset($this->vars['status']); }

			$this->orderHistory();
			$this->getStatus();

			if (isset($this->vars['error']) && $this->vars['error'] > 0) {
				unset($this->vars['error']);
				continue;
			}
			
			if (!empty($this->vars['status']['orders'])) {
				foreach ($this->vars['status']['orders'] as $k=>$v) {
					$this->cancelOrder($v['orderId']);
				}
				continue;
			}

			if (isset($this->vars['status']['history']['isBuyer']) && $this->vars['status']['history']['isBuyer'] == 1 && $this->vars['status'][$this->vars['primary']]['available'] >= ($this->vars['status']['history']['qty']-$this->vars['status']['history']['commission'])) {
				$this->isSeller();
				continue;
			}

			if ((!isset($this->vars['status']['history']['isBuyer']) || (isset($this->vars['status']['history']['isBuyer']) && $this->vars['status']['history']['isBuyer'] == '')) && $this->vars['status'][$this->vars['secondary']]['available']*($this->vars['tradePercentage']/100) > $this->vars['tradeMin']) {
				$this->isBuyer();
				continue;
			}

			sleep($this->vars['sleepTime']);
		}
	}
	
	function isBuyer() {
		
		$this->getTradingView($this->vars['tradingview']['period']['buy']);
		$this->marketPrice();
		$this->getStatus();
		$this->getTrend($this->vars['trendNum']['buy']);

		$action = 0;
		if ($this->vars['status']['tradingview'] > $this->vars['tradingview']['margin'] && (isset($this->vars['status']['action']) && $this->vars['status']['action'] == 'BUY')) { $action++; }

		$price = intval(($this->vars['status']['marketprice']-($this->vars['status']['marketprice']*($this->vars['tradePrice']/100)))*(10 ** $this->vars['digits']['price']))/(10 ** $this->vars['digits']['price']);
		$quantity = intval((($this->vars['status'][$this->vars['secondary']]['available']/$price)*($this->vars['tradePercentage']/100))*(10 ** $this->vars['digits']['quantity']))/(10 ** $this->vars['digits']['quantity']);

		if ($action > 0 && $price*$quantity > $this->vars['tradeMin']) {
			try {
				$order = $this->api->buy($this->vars['symbol'], $quantity, $price);
			} catch (Exception $e) { $this->storeError($e); sleep($this->vars['sleepTime']); return; }
					
			if (isset($order) && !empty($order)) {
				$this->messageTelegram(	"PENDING ORDER (" . $this->vars['secondary'] . " -> " . $this->vars['primary'] . "):" .
										"\n" . "Price: " . $price . " (MP: " . $this->vars['status']['marketprice'] . ")" .
										"\n" . "Quantity: " . $quantity);

				$i = 0;
				while($i < $this->vars['orderRepeat']) {
					try {
						$status = $this->api->orderStatus($this->vars['symbol'], $order['orderId']);
					} catch (Exception $e) {
						$this->storeError($e);
						sleep($this->vars['sleepTime']);
						continue;
					}

					if (isset($status) && isset($status['status']) && $status['status'] == "FILLED") {
						$message =	"ORDER FILLED (" . $this->vars['secondary'] . " -> " . $this->vars['primary'] . "):" . "\nPrice: " . $status['price'] . "\nQuantity: " . $status['origQty'];
						$this->getBalanceUSDT();
						
						if (isset($this->vars['status']['balance']['usdt'])) {
							$message .= "\nBalance: " . $this->vars['status']['balance']['usdt'];
						}

						$this->messageTelegram($message);
						sleep($this->vars['sleepTime']);
						break;
					}
						
					$i++;
					if ($i !== $this->vars['orderRepeat']) {
						sleep($this->vars['sleepTime']);
					}
				}
			}
		} else {
			sleep($this->vars['sleepTime']);
		}
	}

	function isSeller() {

		$this->getTradingView($this->vars['tradingview']['period']['sell']);
		$this->getStatus();
		$this->marketPrice();
		$this->getTrend($this->vars['trendNum']['sell']);

		$action = 0;

		if ($this->vars['status']['history']['price']-($this->vars['status']['history']['price']*($this->vars['stop']['loss']/100)) > $this->vars['status']['marketprice']) { $action++; }
		
		if ($this->vars['status']['tradingview'] < $this->vars['tradingview']['margin']
			&& $this->vars['status']['marketprice'] > ($this->vars['status']['history']['price']+($this->vars['status']['history']['price']*($this->vars['stop']['gain']/100)))
			&& isset($this->vars['status']['action'])
			&& $this->vars['status']['action'] == 'SELL') { $action++; }

		$price = intval(($this->vars['status']['marketprice']+($this->vars['status']['marketprice']*($this->vars['tradePrice']/100)))*(10 ** $this->vars['digits']['price']))/(10 ** $this->vars['digits']['price']);
		$quantity = intval($this->vars['status'][$this->vars['primary']]['available']*(10 ** $this->vars['digits']['quantity']))/(10 ** $this->vars['digits']['quantity']);

		if ($action > 0 && $price*$quantity > $this->vars['tradeMin']) {
			try {
				$order = $this->api->sell($this->vars['symbol'], $quantity, $price);
			} catch (Exception $e) { $this->storeError($e); sleep($this->vars['sleepTime']); return; }
			
			if (isset($order) && !empty($order)) {
					
				$this->messageTelegram(	"PENDING ORDER (" . $this->vars['primary'] . " -> " . $this->vars['secondary'] . "):" .
										"\n" . "Price: " . $price . " (MP: " . $this->vars['status']['marketprice'] . ")" .
										"\n" . "Quantity: " . $quantity);

				$i = 0;
				while($i < $this->vars['orderRepeat']) {
					try {
						$status = $this->api->orderStatus($this->vars['symbol'], $order['orderId']);
					} catch (Exception $e) {
						$this->storeError($e);
						sleep($this->vars['sleepTime']);
						continue;
					}

					if (isset($status) && isset($status['status']) && $status['status'] == "FILLED") {
						$message =	"ORDER FILLED (" . $this->vars['primary'] . " -> " . $this->vars['secondary'] . "):" . "\nPrice: " . $status['price'] . "\nQuantity: " . $status['origQty'];
						$this->getBalanceUSDT();
						
						if (isset($this->vars['status']['balance']['usdt'])) {
							$message .= "\nBalance: " . $this->vars['status']['balance']['usdt'];
						}

						$this->messageTelegram($message);
						sleep($this->vars['sleepTime']);
						break;
					}

					$i++;
					if ($i !== $this->vars['orderRepeat']) {
						sleep($this->vars['sleepTime']);
					}
				}
			}
		} else {
			sleep($this->vars['sleepTime']);
		}
	}

	function cancelOrder($orderId) {
		$i = 0;
		while($i <= $this->vars['orderRepeat']) {
			try {
				$cancel = $this->api->cancel($this->vars['symbol'], $orderId);
			} catch (Exception $e) { $this->storeError($e); break; }
			
			if (isset($cancel) && isset($cancel['status']) && $cancel['status'] === "CANCELED") {
				$this->messageTelegram(	"ORDER CANCELED (" . $this->vars['symbol'] . "): " . $orderId);
				break;
			}

			sleep($this->vars['sleepTime']);
			$i++;
		}
	}

	function getStatus() {
		try {
		    $ticker = $this->api->prices();
		} catch (Exception $e) { $this->storeError($e); }
		
		if (isset($ticker) && is_array($ticker) && !empty($ticker)) {
			try {
				$balances = $this->api->balances($ticker);
			} catch (Exception $e) { $this->storeError($e); }
		}

		if (isset($balances) && !empty($balances)) {
	    	$this->vars['status'][$this->vars['primary']] = (isset($balances[$this->vars['primary']])) ? $balances[$this->vars['primary']] : 0;
	    	$this->vars['status'][$this->vars['secondary']] = (isset($balances[$this->vars['secondary']])) ? $balances[$this->vars['secondary']] : 0;
		}

		try {
			$orders = $this->api->openOrders($this->vars['symbol']);
		} catch (Exception $e) { $this->storeError($e); }
		
		if (isset($orders) && !empty($orders)) {
			$this->vars['status']['orders'] = $orders;
		}
	}
	
	function getBalanceUSDT() {
		$btc = $this->api->btc_value;
		if (isset($btc) && $btc > 0) {
			try {
				$price = $this->api->price("BTCUSDT");
			} catch (Exception $e) { $this->storeError($e); }
			
			if (isset($price) && $price > 0) {
				$this->vars['status']['balance'] = array('btc' => $btc, 'usdt' => $price*$btc);
			}
		}
	}

	function marketPrice() {
		try {
	    	$marketPrice = $this->api->price($this->vars['symbol']);
	    } catch (Exception $e) { $this->storeError($e); }
		if (isset($marketPrice)) {
			$this->vars['status']['marketprice'] = $marketPrice;
		}
	}
	
	function orderHistory() {
		try {
	    	$history = $this->api->history($this->vars['symbol'],1);
	    } catch (Exception $e) { $this->storeError($e); }
		if (isset($history)) {
			$this->vars['status']['history'] = current($history);
		}
	}

	function getTrend($trendNum) {
		$prices = array();
		try {
			$prices = $this->api->aggTrades($this->vars['symbol']);
		} catch (Exception $e) { $this->storeError($e); }
		
		if (!empty($prices)) {
			$prices = array_slice($prices, 0, $trendNum);
			$slice = round(count($prices)/2);
			
			$prices1 = array_slice($prices, 0, $slice);
			$p = 0; $q = 0;
			foreach ($prices1 as $row) {
				$p = $p+$row['price'];
				$q++;
			}
			$price1 = $p/$q;
			
			$prices2 = array_slice($prices, $slice);
			$p = 0; $q = 0;
			foreach ($prices2 as $row) {
				$p = $p+$row['price'];
				$q++;
			}
			$price2 = $p/$q;

			if ($price1 > $price2) { $this->vars['status']['action'] = "BUY"; }
			if ($price1 < $price2) { $this->vars['status']['action'] = "SELL"; }
		}
	}
	
	function getTradingView($period) {
		$curl = curl_init();
	    $postField = '{"symbols":{"tickers":["BINANCE:' . $this->vars['symbol'] . '"],"query":{"types":[]}},"columns":["Recommend.All|' . $period . '"]}';
	    curl_setopt_array($curl, array(
			CURLOPT_URL => $this->vars['tradingview']['url'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => "POST",
			CURLOPT_POSTFIELDS => $postField,
			CURLOPT_HTTPHEADER => array(
				"accept: */*",
				"accept-language: en-GB,en-US;q=0.9,en;q=0.8",
				"cache-control: no-cache",
				"content-type: application/x-www-form-urlencoded",
				"origin: https://www.tradingview.com",
				"referer: https://www.tradingview.com/",
				"user-agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.96 Safari/537.36"
			)
		));

	    try {
		    $result = curl_exec($curl);
		    if (isset($result) && !empty($result)) {
			    $j = json_decode($result, true);
				if (isset($j['data'][0]['d'][0])) {
					$this->vars['status']['tradingview'] = $j['data'][0]['d'][0];
				}
		    }
		} catch (Exception $e) { $this->storeError($e); }
    }
	
	function storeError($e) {
		if (!isset($this->vars['error'])) {
			$this->vars['error'] = 1;
		} else {
			$this->vars['error'] = $this->vars['error']+1;
		}
		error_log("ERROR: " . $e);
	}
	
	function messageTelegram($msg) {
		if ($msg !== '' && $this->vars['telegram']['token'] !== '' && $this->vars['telegram']['channel'] !== '') {
			try {
				$tgLog = new TgLog($this->vars['telegram']['token']);
				$sendMessage = new SendMessage();
				$sendMessage->chat_id = $this->vars['telegram']['channel'];
				$sendMessage->text = $msg;
				$tgLog->performApiRequest($sendMessage);
			} catch (Exception $e) { error_log("ERROR: " . $e); }
		}
	}
}

try {
    $trader = new BinanceTrader();
    $trader->trade();
} catch (Exception $e) { error_log($e); }