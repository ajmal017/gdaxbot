<?php

/**
 * Created by PhpStorm.
 * User: joeldg
 * Date: 6/25/17
 * Time: 1:46 PM
 */

namespace App\Traits;

use App\Util\BrokersUtil;
use App\Util\Console;
use App\Util\Indicators;

/**
 * Class Signals
 * @package Bowhead\Traits
 *          Forex signals
 *
 *          RSI (14)
 *          Stoch (9,6)
 *          STOCHRS(14)
 *          MACD(12,26)
 *          ADX(14)
 *          Williams %R
 *          CCI(14)
 *          ATR(14)
 *          Highs/Lows(14)
 *          Ultimate Oscillator
 *          ROC
 *          Bull/Bear Power(13) Elder-Ray
 */
trait Signals {

    /**
     * @var
     */
    protected $indicators;

    /**
     * @param bool $return
     * @param bool $compile
     */
    public function signals($instruments = null) {
        $inds  = ['rsi', 'stoch', 'stochrsi', 'macd', 'adx', 'willr', 'cci', 'atr', 'hli', 'ultosc', 'roc', 'er'];

        if (empty($instruments)) {
            $instruments = [getenv('CRYPTOCOIN')];
        }

        $indicators = new Indicators();
 
        foreach ($instruments as $pair) {
            $data              = $this->getRecentData($pair);
            
            $flags             = [];
            $flags['rsi']      = $indicators->rsi($pair, $data);
            $flags['stoch']    = $indicators->stoch($pair, $data);
            $flags['stochrsi'] = $indicators->stochrsi($pair, $data);
            $flags['macd']     = $indicators->macd($pair, $data);
            $flags['adx']      = $indicators->adx($pair, $data);
            $flags['willr']    = $indicators->willr($pair, $data);
            $flags['cci']      = $indicators->cci($pair, $data);
            $flags['atr']      = $indicators->atr($pair, $data);
            $flags['hli']      = $indicators->hli($pair, $data);
            $flags['ultosc']   = $indicators->ultosc($pair, $data);
            $flags['roc']      = $indicators->roc($pair, $data);
            $flags['er']       = $indicators->er($pair, $data);

            $symbollines[$pair] = $flags;
        }


        $return = $ret    = [];
        foreach ($symbollines as $symbol => $datas) {
            $ret[$symbol]         = [];
            $ret[$symbol]['buy']  = 0;
            $ret[$symbol]['sell'] = 0;

            foreach ($datas as $data) {
                $ret[$symbol]['buy']  += ($data == 1 ? 1 : 0);
                $ret[$symbol]['sell'] += ($data == -1 ? 1 : 0);
            }
        }

        foreach ($ret as $k => $r) {
            $return[$k] = 'NONE';
            $return[$k] = ($r['buy'] > 6 ? 'WEAK BUY' : $return[$k]);
            $return[$k] = ($r['buy'] > 8 ? 'GOOD BUY' : $return[$k]);
            $return[$k] = ($r['buy'] > 9 ? 'STRONG BUY' : $return[$k]);
            $return[$k] = ($r['buy'] > 10 ? 'VERY STRONG BUY' : $return[$k]);
            $return[$k] = ($r['sell'] > 6 ? 'WEAK SELL' : $return[$k]);
            $return[$k] = ($r['sell'] > 8 ? 'GOOD SELL' : $return[$k]);
            $return[$k] = ($r['sell'] > 9 ? 'STRONG SELL' : $return[$k]);
            $return[$k] = ($r['sell'] > 10 ? 'VERY STRONG SELL' : $return[$k]);
        }

        return ['symbol'=>$symbollines,'ret' => $ret, 'strength' => $return];
    }

}