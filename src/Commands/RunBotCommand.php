<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace App\Commands;

use App\Util\Indicators;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use App\Traits\ActualizeBuysAndSells;
use App\Strategies\Traits\TrendingLinesStrategy;

/**
 * Description of RunBotCommand
 *
 * @author patrick
 */
class RunBotCommand extends Command
{

    use ActualizeBuysAndSells;

    protected $gdaxService;
    protected $orderService;
    protected $settingsService;

    protected function configure()
    {
        $this->setName('bot:run')
                ->setDescription('Runs the bot for 1 cycle use cron to call this command.')
                ->addOption('sandbox', null, InputOption::VALUE_NONE, 'Run bot in sandbox so no real trades will be made.')
                ->setHelp('Runs the bot for 1 cycle use cron to call this command.');
    }

    protected function getStrategy()
    {
        /**
         * Available strategy's
         */
        $strategies = [
            'Trendlines' => new \App\Strategies\TrendingLinesStrategy()
        ];

        return $strategies['Trendlines'];
    }



    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("=== RUN [" . date('Y-m-d H:i:s') . "] ===");
        
        // Settings
        $settingsService = new \App\Services\SettingsService();

        $config                       = [];
        $config['max_orders_per_run'] = getenv('MAX_ORDERS_PER_RUN');
        $config                       = array_merge($config, $settingsService->getSettings());

        $botactive = ($config['botactive'] == 1 ? true : false);
        if (!$botactive) {
            $output->writeln("<info>Bot is not active at the moment</info>");
            return;
        }

        $orderService = new \App\Services\OrderService();

        $gdaxService = new \App\Services\GDaxService();
        $gdaxService->setCoin(getenv('CRYPTOCOIN'));

        $sandbox = false;
        if ($input->getOption('sandbox')) {
            $output->writeln('<info>Running in sandbox mode</info>');
            $sandbox = true;
        }

        $gdaxService->connect($sandbox);

        //Cleanup
        $this->gdaxService  = $gdaxService;
        $this->orderService = $orderService;

        $this->orderService->garbageCollection();
        $this->actualize();
        $this->actualizeSells();
        $this->orderService->fixRejectedSells();
        
        // Now we can use strategy       
        $indicators = new Indicators();
        $strategy   = $this->getStrategy();
        $strategy->setIndicicators($indicators);
        $strategy->setOrderService($orderService);
        $strategy->setGdaxService($gdaxService);
        $strategy->settings($config);
        
        // Even when the limit is reached, i want to know the signal
        $signal = $strategy->getSignal();
        echo "Signal: ".$signal."\n";
                
        $currentPrice      = $gdaxService->getCurrentPrice();
        
        
        
        
        // WIP
        $strategy->stopLoss($signal,$currentPrice);
        
        // Create safe limits
        $topLimit    = $config['top'];
        $bottomLimit = $config['bottom'];
         if (!$currentPrice || $currentPrice < 1 || $currentPrice > $topLimit || $currentPrice < $bottomLimit) {
            $output->writeln(sprintf("<info>Treshold reached %s  [%s]  %s so no buying for now</info>", $bottomLimit, $currentPrice, $topLimit));
            return;
        }

        $output->writeln("** Place sell orders");
        $strategy->closePosition();

        $this->actualizeBuys();
       
        $output->writeln("** Place buy orders");
        $strategy->createPosition($currentPrice);

        $output->writeln("=== DONE " . date('Y-m-d H:i:s') . " ===");
    }

}
