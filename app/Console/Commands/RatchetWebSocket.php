<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Events\eventTrigger; // Linked the event
use Carbon\Carbon; // date simple lib https://github.com/briannesbitt/Carbon
use Illuminate\Support\Facades\DB;

class RatchetWebSocket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ratchet:start';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ratchet/pawl websocket client console application';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        echo "*****Ratchet websocket console command(app) started!*****\n";

        // The code from: https://github.com/ratchetphp/Pawl

        $loop = \React\EventLoop\Factory::create();
        $reactConnector = new \React\Socket\Connector($loop, [
            'dns' => '8.8.8.8', // Does not work through OKADO inernet provider. Timeout error
            'timeout' => 10
        ]);

        $connector = new \Ratchet\Client\Connector($loop, $reactConnector);

        $connector('wss://api.bitfinex.com/ws/2', [], ['Origin' => 'http://localhost'])
            ->then(function(\Ratchet\Client\WebSocket $conn) {
                $conn->on('message', function(\Ratchet\RFC6455\Messaging\MessageInterface $msg) use ($conn) {

                    RatchetWebSocket::out($msg); // Call the function when the event is received
                    //echo $msg;

                });

                $conn->on('close', function($code = null, $reason = null) {
                    echo "Connection closed ({$code} - {$reason})\n";
                });

                //$conn->send(['event' => 'ping']);
                $z = json_encode([
                    //'event' => 'ping', // 'event' => 'ping'
                    'event' => 'subscribe',
                    'channel' => 'trades',
                    'symbol' => 'tBTCUSD'
                ]);
                $conn->send($z);

            }, function(\Exception $e) use ($loop) {
                echo "Could not connect: {$e->getMessage()}\n";
                $loop->stop();
            });

        $loop->run();

    }

    // 'te', 'tu' Flags explained http://blog.bitfinex.com/api/websocket-api-update/
    // 'te' - When the trades is regeristed at the exchange
    // 'tu' - When the actual trade has happened. Delayed for 1-2 seconds from 'te'
    // 'hb' - Heart beating. If there is no new message in the channel for 1 second, Websocket server will send you an heartbeat message in this format
    // SNAPSHOT (the initial message) https://docs.bitfinex.com/docs/ws-general

    public $dateCompeareFlag = true;
    public $tt; // Time

    public $barHigh = 0; // For high value calculation
    public $barLow = 9999999;

    public $trade_flag = "all";
    public $add_bar_long = true; // Count closed position on the same be the signal occurred. The problem is when the position is closed the close price of this bar goes to the next position
    public $add_bar_short = true;
    public $position; // Current position


    public function out($message)
    {


        $jsonMessage = json_decode($message->getPayload(), true); // Methods http://socketo.me/api/class-Ratchet.RFC6455.Messaging.MessageInterface.html
        //print_r($jsonMessage);
        //print_r(array_keys($z));
        //echo $message->__toString() . "\n"; // Decode each message

        if (array_key_exists('chanId',$jsonMessage)){
            $chanId = $jsonMessage['chanId']; // Parsed channel ID then we are gonna listen exactley to this channel number. It changes each time you make a new connection
        }

        $nojsonMessage = json_decode($message->getPayload());
        if (!array_key_exists('event',$jsonMessage)){ // All messages except first two associated arrays
            if ($nojsonMessage[1] == "te") // Only for the messages with 'te' flag. The faster ones
            {
                //echo "id: " . $nojsonMessage[2][0];
                //echo " date: " . gmdate("Y-m-d G:i:s", ($nojsonMessage[2][1] / 1000));
                //echo " volume: " . $nojsonMessage[2][2];
                //echo " price: " . $nojsonMessage[2][3] . "\n";

                // Take seconds off and add 1 min. Do it only once per interval (1min)
                if ($this->dateCompeareFlag) {
                    $x = date("Y-m-d H:i", $nojsonMessage[2][1] / 1000) . "\n"; // Take seconds off. Convert timestamp to date
                    $this->tt = strtotime($x . ' 1 minute'); // Added 1 minute. Timestamp
                    $this->dateCompeareFlag = false;
                }

                // Make a signal when value reaches over added 1 minute
                echo gmdate("Y-m-d G:i:s", ($nojsonMessage[2][1] / 1000)) . " / " . floor(($nojsonMessage[2][1] / 1000)) . " * " . $this->tt . " price: " . $nojsonMessage[2][3] . " vol: " . $nojsonMessage[2][2] . " pos: " . $this->position . "\n";

                // Calculate high and low of the bar then pass it to the chart in $messageArray
                if ($nojsonMessage[2][3] > $this->barHigh) // High
                {
                    $this->barHigh = $nojsonMessage[2][3];
                }

                if ($nojsonMessage[2][3] < $this->barLow) // Low
                {
                    $this->barLow = $nojsonMessage[2][3];
                }

                // RATCHET ERROR GOES HERE, WHILE INITIAL START FROM GIU. trying to property of non-object
                // Update high, low and close of the current bar in DB. Update the record on each trade. Then the new bar will be issued - we will have actual values updated in the DB
                DB::table('btc_history')
                    ->where('id', DB::table('btc_history')->orderBy('time_stamp', 'desc')->first()->id) // id of the last record. desc - descent order
                    ->update([
                        'close' => $nojsonMessage[2][3],
                        'high' => $this->barHigh,
                        'low' => $this->barLow,
                    ]);

                // Trades watch

                $x = (DB::table('btc_history')->orderBy('time_stamp', 'desc')->get())[0]->id; // Quantity of all records in DB

                $price_channel_high_value =
                    DB::table('btc_history')
                        ->where('id', ($x - 1)) // Penultimate record. One before last
                        ->value('price_channel_high_value');

                $price_channel_low_value =
                    DB::table('btc_history')
                        ->where('id', ($x - 1)) // Penultimate record. One before last
                        ->value('price_channel_low_value');

                if (($nojsonMessage[2][3] > $price_channel_high_value) && ($this->trade_flag == "all" || $this->trade_flag == "long")){ // price > price channel
                    echo "####### HIGH TRADE!\n";

                    //$long_trades[] = [$all_table_values[$element_index]->time_stamp, $all_table_values[$element_index]->close]; // Added long marker

                    $this->trade_flag = "short";
                    $this->position = "long";
                    $this->add_bar_long = true;

                    // Add trade info to the last(current) bar(record)
                    DB::table('btc_history')
                        ->where('id', $x)
                        ->update([
                            'trade_date' => gmdate("Y-m-d G:i:s", ($nojsonMessage[2][1] / 1000)),
                            'trade_price' => $nojsonMessage[2][3],
                            'trade_direction' => "buy",
                            'trade_volume' => 1
                        ]);

                    $messageArray['flag'] = "buy"; // Send flag to VueJS app.js

                }

                if (($nojsonMessage[2][3] < $price_channel_low_value) && ($this->trade_flag == "all"  || $this->trade_flag == "short")) { // price < price channel
                    echo "####### LOW TRADE!\n";

                    //$short_trades[] = [$all_table_values[$element_index]->time_stamp, $all_table_values[$element_index]->close]; // Added short marker

                    $this->trade_flag = "long";
                    $this->position = "short";
                    $this->add_bar_short = true;

                    // Add trade info to the last(current) bar(record)
                    DB::table('btc_history')
                        ->where('id', $x)
                        ->update([
                            'trade_date' => gmdate("Y-m-d G:i:s", ($nojsonMessage[2][1] / 1000)),
                            'trade_price' => $nojsonMessage[2][3],
                            'trade_direction' => "sell",
                            'trade_volume' => 1
                        ]);

                    $messageArray['flag'] = "sell"; // Send flag to VueJS app.js
                }




                // New bar is issued
                if (floor(($nojsonMessage[2][1] / 1000)) >= $this->tt){
                    echo "************************************** new bar issued\n\n";
                    $messageArray['flag'] = true; // Added true flag which will inform JS that new bar is issued
                    $this->dateCompeareFlag = true;

                    // Add new bar to the DB
                    DB::table('btc_history')->insert(array( // Record to DB
                        'date' => gmdate("Y-m-d G:i:s", ($nojsonMessage[2][1] / 1000)), // Date in regular format. Converted from unix timestamp
                        'time_stamp' => $nojsonMessage[2][1],
                        'open' => $nojsonMessage[2][3],
                        'close' => $nojsonMessage[2][3],
                        'high' => $nojsonMessage[2][3],
                        'low' => $nojsonMessage[2][3],
                        'volume' => $nojsonMessage[2][2],
                    ));

                    // Recalculate price channel. Controller call as a method
                    app('App\Http\Controllers\indicatorPriceChannel')->index();

                }

                // Add calculated values to associative array
                $messageArray['tradeId'] = $nojsonMessage[2][0];
                $messageArray['tradeDate'] = $nojsonMessage[2][1];
                $messageArray['tradeVolume'] = $nojsonMessage[2][2];
                $messageArray['tradePrice'] = $nojsonMessage[2][3];
                $messageArray['tradeBarHigh'] = $this->barHigh; // Bar high
                $messageArray['tradeBarLow'] = $this->barLow; // Bar Low

                // Send filled associated array in the event
                event(new eventTrigger($messageArray)); // Fire new event. Events are located in app/Events

                // Reset high, low of the bar but do not out put these values to the chart
                if ($this->dateCompeareFlag == true){
                    $this->barHigh = 0;
                    $this->barLow = 9999999;
                }

            } // if

        } // if

    } // out
}
