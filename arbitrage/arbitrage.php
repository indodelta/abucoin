<?php

require_once('../common/tools.php');

declare(ticks = 1);
function sig_handler($sig) {
  global $sig_stop;
    switch($sig) {
        case SIGINT:
        case SIGTERM:
          print_dbg("signal $sig catched! Exiting...", true);
          $sig_stop = true;
    }
}
pcntl_signal(SIGINT,  "sig_handler");
pcntl_signal(SIGTERM, "sig_handler");
$sig_stop = false;

@define('BUY_TRESHOLD', 0.000001);
@define('CRITICAL_BUY_TRESHOLD', -0.000005);
@define('CRITICAL_BUY_TRESHOLD2', -0.00001);

$market1 = new Market($argv[1]);
$market2 = new Market($argv[2]);

$profits = [];

$symbol_list = getCommonProducts($market1, $market2);

print "retrieve balances\n";
while (true) { try {
    $market1->getBalance();
    $market2->getBalance();
    break;
  } catch (Exception $e) {}
}

$btc_start_cash = $market1->api->balances['BTC'] + $market2->api->balances['BTC'];

$nLoops = 0;
while(true) {
  foreach( $symbol_list as $symbol) {
    if (!$sig_stop) {
      print "Testing $symbol trade\n";
      $base = $market1->products[$symbol]->base;
      try {
        @$profits[$base] += testSwap($symbol, $market1, $market2);
      }
      catch (Exception $e)
      {
        print $e;
        //refresh balances
        sleep(3);
        try {
          $market1->getBalance();
          $market2->getBalance();
        }catch (Exception $e){}
      }
      try {
        @$profits[$base] += testSwap($symbol, $market2, $market1);
      }
      catch (Exception $e) {
        print $e;
        //refresh balances
        sleep(3);
        if($e->getMessage() == 'Rest API trading is not enabled.')
        {
          sleep(3600);//exchange maintenance ?
          break;
        }
        try {
          $market1->getBalance();
          $market2->getBalance();
        }catch (Exception $e){}
      }
    } else {
      exit();
    }

    if($nLoops == PHP_INT_MAX)
      $nLoops=0;
    else
      $nLoops++;

    if( ($nLoops % 10) == 0) {
      //ping api
      try {
        while($market1->api->ping() === false) {
          print "Failed to ping {$market1->api->name} api. Sleeping...\n";
          sleep(30);
        }
        while($market2->api->ping() === false) {
          print "Failed to ping {$market2->api->name} api. Sleeping...\n";
          sleep(30);
        }
      }catch (Exception $e){}

      print "Refreshing balances\n";
      try {$market1->api->getBalance();}
        catch (Exception $e){}
      try {$market2->api->getBalance();}
        catch (Exception $e){}

      try {
        foreach([$market1, $market2] as $market) {
          //refresh product infos
          if($market instanceof CobinhoodApi)
            $market->updateProductList();
        }
      } catch (Exception $e){}
    }
  }

  $btc_cash_roll = $market1->api->balances['BTC'] + $market2->api->balances['BTC'];
  print "~~~~ ".date("Y-m-d H:i:s")." ~~~~~\n\n";
  foreach($profits as $base => $profit) {
    print "~~~~cumulated gain: $profit $base~~~~~\n\n";
  }
  print "~~~~{$market2->api->name}:{$market2->api->balances['BTC']}BTC  {$market1->api->name}:{$market1->api->balances['BTC']}BTC~~~~\n\n";
  print "~~~~Cash roll: $btc_cash_roll BTC, GAIN=".($btc_cash_roll-$btc_start_cash)."BTC~~~~\n\n";

  print "~~~~Api call stats: {$market2->api->name}: {$market2->api->api_calls_rate}/min , {$market1->api->name}: {$market1->api->api_calls_rate}/min~~~~\n\n";

}

function testSwap($symbol, $buy_market, $sell_market)
{
  $profit = 0;
  $final_gains['base'] = 1; //dummy init
  $buy_product = $buy_market->products[$symbol];
  $sell_product = $sell_market->products[$symbol];
  $alt = $buy_product->alt;
  $base = $sell_product->base;
  while($final_gains['base'] > 0) {
    $base_cash_roll = $buy_market->api->balances[$base] + $sell_market->api->balances[$base];
    $get_base_market = $buy_market->api->balances[$base] > $sell_market->api->balances[$base];
    $get_base_market_critical = $base_cash_roll > 0.001 ? $sell_market->api->balances[$base] < $base_cash_roll * 0.1 /*10% of cashroll*/: false;

    $min_order_size_base = max($buy_product->min_order_size_base, $sell_product->min_order_size_base);
    $min_order_size_alt = max($buy_product->min_order_size, $sell_product->min_order_size);

    if( $sell_market->api->balances[$alt] < $min_order_size_alt
        || $buy_market->api->balances[$base] < $min_order_size_base)
      break;

    $buy_book = $buy_market->refreshBook($buy_product, $min_order_size_base, $min_order_size_alt);
    $sell_book = $sell_market->refreshBook($sell_product, $min_order_size_base, $min_order_size_alt);

    $sell_price = $sell_book['bids']['price'];
    $buy_price = $buy_book['asks']['price'];
    $tradeSize = min($sell_book['bids']['size'], $buy_book['asks']['size']);
    print("tradesize $tradeSize\n");
    $buy_fees = $buy_product->fees;
    $sell_fees = $sell_product->fees;

    $expected_gains = computeGains($buy_price, $buy_fees, $sell_price, $sell_fees, $tradeSize);
    //swap conditions
    $do_swap = false;
    if($base == 'BTC') {
      if ($expected_gains['base'] > BUY_TRESHOLD ||
         ($get_base_market_critical && ($expected_gains['base'] >= CRITICAL_BUY_TRESHOLD)) ||
         ($get_base_market && $expected_gains['base'] >= 0) ) {
           $do_swap = true;
         }
    } else if ($expected_gains['base'] > 0) {
      $do_swap = true;
    }

    if ($do_swap) {
      //avoid swap to big when gain is <0
      if ($get_base_market_critical && $base == 'BTC') {
        $half_cash = $base_cash_roll / 2;
        if ((($tradeSize * $sell_price) > $half_cash) && $expected_gains['base'] < 0) {
          $new_tradeSize = $half_cash / $sell_price > $min_order_size_alt ? $half_cash / $sell_price: $min_order_size_alt;
          print_dbg("Critical swap: reducing tradesize from $tradeSize to $new_tradeSize $alt", true);
          $tradeSize = $new_tradeSize;
        }
      }
      $buy_market->getBalance();
      $sell_market->getBalance();

      print "do arbitrage for {$symbol}. estimated gain: ".number_format($expected_gains['percent'], 3)."%";
      $status = do_arbitrage($symbol, $sell_market, $sell_book['bids']['order_price'], $buy_market, $buy_book['asks']['order_price'], $tradeSize);
      if ($status['buy']['filled_size'] > 0 && $status['sell']['filled_size'] > 0) {

        if ($status['buy']['filled_size'] != $status['sell']['filled_size'])
          print_dbg("Different tradesizes buy:{$status['buy']['filled_size']} != sell:{$status['sell']['filled_size']}");

        $tradeSize = min($status['buy']['filled_size'] , $status['sell']['filled_size']);
        $final_gains = computeGains($status['buy']['price'], $buy_fees, $status['sell']['price'], $sell_fees, $tradeSize);
        $profit += $final_gains['base'];
        print("log tx\n");
        $trade_str = date("Y-m-d H:i:s").": {$final_gains['base']} $base {$expected_gains['percent']}% ({$final_gains['percent']}%)\n";
        file_put_contents('gains',$trade_str,FILE_APPEND);

        //Just in case
        $buy_market->api->balances[$alt] += $status['buy']['filled_size'];
        $buy_market->api->balances[$base] -= $tradeSize * $status['sell']['price'];
        $sell_market->api->balances[$base] += $tradeSize * $status['buy']['price'];
        $sell_market->api->balances[$alt] -= $status['sell']['filled_size'];
      }
      else
        $final_gains['base'] = 0;
    }
    else
        $final_gains['base'] = 0;
  }
  return $profit;
}
