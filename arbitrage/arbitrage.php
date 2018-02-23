<?php

require_once('../common/tools.php');

$keys = json_decode(file_get_contents("../common/private.keys"));
$abucoinsApi = new AbucoinsApi($keys->abucoins);
$CryptopiaApi = new CryptopiaApi($keys->cryptopia);

$profit = 0;
while(true)
{
  foreach( ['GNT' ,'HSR', 'LTC', 'XMR', 'STRAT', 'ETC', 'TRX', 'ETH', 'ARK', 'BCH', 'REP', 'DASH', 'ZEC'] as $alt)
  {
    try
    {
      print "Testing $alt trade\n";
      $AbuOrderbook = new OrderBook($abucoinsApi, "$alt-BTC");
      $abuBook = $AbuOrderbook->book;
      $CryptOrderbook = new OrderBook($CryptopiaApi, "$alt-BTC");
      $cryptBook = $CryptOrderbook->book;

      $fees_percent = $CryptOrderbook->product->fees + $AbuOrderbook->product->fees;

      //SELL Cryptopia => BUY Abucoins
      $sell_price = $cryptBook['bids']['price'];
      $buy_price = $abuBook['asks']['price'];
      var_dump($sell_price); var_dump($buy_price);
      $tradeSize = $cryptBook['bids']['size'] > $abuBook['asks']['size'] ? $abuBook['asks']['size'] : $cryptBook['bids']['size'];
      $gain_percent = ((($sell_price *((100-$CryptOrderbook->product->fees)/100))/
                      ($buy_price *((100+$AbuOrderbook->product->fees)/100)))-1)*100;

      //print("GAIN= $gain_percent\n");
      if($gain_percent>0.1 && $gain_percent < 20 /*price should be double checked for cryptopia*/)
      {
        print "SELL Cryptopia => BUY Abucoins: GAIN ".number_format($gain_percent,3)."%\n";
        $tradeSize_btc = do_arbitrage($CryptOrderbook, $sell_price, $AbuOrderbook, $buy_price, $tradeSize);
        if($tradeSize_btc>0)
        {
          print("log tx\n");
          $gain_btc = $tradeSize_btc*$gain_percent;
          $profit+=$gain_btc;
          $trade_str = date("Y-m-d H:i:s").": +$gain_btc BTC\n";
          file_put_contents('gains',$trade_str,FILE_APPEND);
        }
      }

      $abuBook = $AbuOrderbook->refresh();
      $cryptBook = $CryptOrderbook->refresh();

      //SELL Abucoins => BUY Cryptopia
      $sell_price = $abuBook['bids']['price'];
      $buy_price = $cryptBook['asks']['price'];
      $tradeSize = $cryptBook['asks']['size'] > $abuBook['bids']['size'] ? $abuBook['bids']['size'] : $cryptBook['asks']['size'];

      $gain_percent = ((($sell_price *((100-$AbuOrderbook->product->fees)/100))/
                      ($buy_price *((100+$CryptOrderbook->product->fees)/100)))-1)*100;


      if($gain_percent>0.1 && $gain_percent < 20 /*price should be double checked for cryptopia*/)
      {
        print "SELL Abucoins => BUY Cryptopia: GAIN ".number_format($gain_percent,3)."%\n";
        $tradeSize_btc = do_arbitrage($AbuOrderbook, $sell_price, $CryptOrderbook, $buy_price, $tradeSize);
        if($tradeSize_btc>0)
        {
          print("log tx\n");
          $gain_btc = $tradeSize_btc*$gain_percent;
          $profit+=$gain_btc;
          $trade_str = date("Y-m-d H:i:s").": +$gain_btc BTC\n";
          file_put_contents('gains',$trade_str,FILE_APPEND);
        }
      }
    }
    catch (Exception $e)
    {
      print $e;
    }

    //sleep(1);
  }
  print "~~~~~~~~~~~~~~cumulated profit: $profit BTC~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
}
