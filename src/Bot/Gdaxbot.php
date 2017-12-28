<?php

namespace App\Bot;

use Carbon\Carbon;

/**
 * Description of Gdaxbot
 *
 * @author patrick
 */
class Gdaxbot {

    protected $conn;
    protected $endpoint;
    protected $spread;
    protected $order_size;
    protected $max_orders_per_run;
    protected $waitingtime;
    protected $lifetime;
    protected $api_key;
    protected $api_secret;
    protected $api_password;
    protected $db;
    protected $client;
    protected $accountEUR;
    protected $accountLTC;
    protected $pendingBuyPrices;
    protected $buyingTreshold;
    protected $sellingTreshold;

// Get the number of open LTC orders 
// Calc allowed number of order = max_orders - open orders.
// Get the max bid price and substract 10
    public function __construct($conn) {
        $this->conn = $conn;


        $this->endpoint = getenv('GDAX_ENDPOINT');
        $this->spread = getenv('SPREAD');
        $this->order_size = getenv('ORDER_SIZE');
        $this->max_orders = getenv('MAX_ORDERS');
        $this->max_orders_per_run = getenv('MAX_ORDERS_PER_RUN');
        $this->waitingtime = getenv('WAITINGTIME');
        $this->lifetime = getenv('LIFETIME');
        $this->buyingTreshold = getenv('BUYINGTRHESHOLD');
        $this->sellingTreshold = getenv('SELLINGTRESHOLD');


        $this->db = new \PDO('sqlite:orders.sqlite');

        $this->client = new \GDAX\Clients\AuthenticatedClient(
                getenv('GDAX_API_KEY'), getenv('GDAX_API_SECRET'), getenv('GDAX_PASSWORD')
        );
    }

    public function createDatabase() {

        $sql = "CREATE TABLE orders (id INTEGER PRIMARY KEY AUTO_INCREMENT, parent_id integer, side varchar(10), amount decimal(15,9),status varchar(10), order_id varchar(40), created_at datetime, updated_at timestamp)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
    }

    public function purgeDatabase() {
        $sql = 'delete from orders';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
    }

    public function deleteOrder($id) {
        $sql = 'delete from orders WHERE id = :id';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->execute();
    }

    public function updateOrder($id, $side) {
        $sql = 'update orders SET side = :side WHERE id = :id';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->bindValue('side', $side);
        $stmt->execute();
    }

    public function updateOrderStatus($id, $status) {
        $sql = 'update orders SET status = :status WHERE id = :id';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->bindValue('status', $status);
        $stmt->execute();
    }

    public function insertOrder($side, $order_id, $amount, $status = 'pending', $parent_id = 0) {
        $sql = 'insert into orders SET side = :side, order_id = :orderid, amount = :amount,status = :status, parent_id = :parentid, created_at = :createdat';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('side', $side);
        $stmt->bindValue('orderid', $order_id);
        $stmt->bindValue('amount', $amount);
        $stmt->bindValue('status', $status);
        $stmt->bindValue('parentid', $parent_id);

        $stmt->bindValue('createdat', date('Y-m-d H:i:s'));

        $stmt->execute();

        $lastId = $this->conn->query('SELECT max(id) as insertid FROM orders')->fetch();

        return (int) $lastId['insertid'];
    }

    public function deleteOrdersWithoutOrderId() {
        $sql = "update orders SET status = :status WHERE order_id = '' AND status <> :status";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('status', 'deleted');
        $stmt->execute();
    }

    public function getPendingBuyOrders() {
        $sql = "SELECT * FROM orders WHERE side='buy' AND (status = 'pending' or status='open')";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function fetchAllOrders($status = 'pending') {
        $sql = 'SELECT * FROM orders WHERE status = :status';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('status', $status);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function fetchOrder($id) {
        $sql = 'SELECT * FROM orders WHERE id = :id';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->execute();

        return $stmt->fetch();
    }

    public function fetchOrderByOrderId($order_id) {
        $sql = 'SELECT * FROM orders WHERE order_id = :orderid';
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('orderid', $order_id);
        $stmt->execute();

        return $stmt->fetch();
    }

    public function getOrdersBySide($side, $status = 'pending') {
        $sql = "SELECT * FROM orders WHERE side = :side AND status = :status";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('side', $side);
        $stmt->bindValue('status', $status);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getOrdersOpenSells() {
        $sql = "SELECT * FROM orders WHERE side = :side AND (status = 'pending' OR status = 'open')";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('side', 'sell');
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getOrdersOpenBuys() {
        $sql = "SELECT * FROM orders WHERE side = :side AND (status = 'pending' OR status = 'open')";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue('side', 'buy');
        $stmt->execute();

        return $stmt->fetchAll();
    }
    
    
    public function listRowsFromDatabase() {
        $currentPendingOrders = $this->fetchAllOrders();
        foreach ($currentPendingOrders as $row) {
            printf("%s| %s| %s| %s\n", $row['created_at'], $row['side'], $row['amount'], $row['order_id']);
        }
    }

    public function getAccounts() {
        $accounts = $this->client->getAccounts();

        //Get the accounts
        foreach ($accounts as $account) {
            $currency = $account->getCurrency();
            if ($currency == 'LTC') {
                $this->accountLTC = (new \GDAX\Types\Request\Authenticated\Account())->setId($account->getId());
            }

            if ($currency == 'EUR') {
                $this->accountEUR = (new \GDAX\Types\Request\Authenticated\Account())->setId($account->getId());
            }
        }
    }

    public function getCurrentPrice() {
        $product = (new \GDAX\Types\Request\Market\Product())->setProductId(\GDAX\Utilities\GDAXConstants::PRODUCT_ID_LTC_EUR);
        $productTicker = $this->client->getProductTicker($product);

        //Current asking price
        $startPrice = $productTicker->getPrice();

        return $startPrice;
    }

    
    /**
     * Check if we have added orders manually and add them to the database.
     */
    public function actualize() {
       
        
        $listOrders = (new \GDAX\Types\Request\Authenticated\ListOrders())
                ->setStatus(\GDAX\Utilities\GDAXConstants::ORDER_STATUS_OPEN)
                ->setProductId(\GDAX\Utilities\GDAXConstants::PRODUCT_ID_LTC_EUR);

        $orders = $this->client->getOrders($listOrders);
        foreach ($orders as $order) {
            $order_id = $order->getId();
            $row = $this->fetchOrderByOrderId($order_id);
            if (!$row) {
                $this->insertOrder($order->getSide(), $order->getId(), $order->getPrice());
            }
        }
    }

    public function actualizeBuys() {
        $rows = $this->getOrdersOpenBuys();

        foreach ($rows as $row) {
            $order = (new \GDAX\Types\Request\Authenticated\Order())->setId($row['order_id']);
            $orderData = $this->client->getOrder($order);
            
            if ($orderData->getStatus()) {
                $this->updateOrderStatus($row['id'], $orderData->getStatus());

                //echo "Actualize sells with order id: " . $order->getId() . "\n";
            }
            
            if ($orderData->getStatus()) {
                $this->updateOrderStatus($row['id'], $orderData->getStatus());
            } else {
                $this->updateOrderStatus($row['id'], $orderData->getMessage());
            }
        }
    }
    
    public function actualizeSells() {
        $rows = $this->getOrdersOpenSells();

        foreach ($rows as $row) {
            $order = (new \GDAX\Types\Request\Authenticated\Order())->setId($row['order_id']);
            $orderData = $this->client->getOrder($order);

            if ($orderData->getStatus()) {
                $this->updateOrderStatus($row['id'], $orderData->getStatus());

                //echo "Actualize sells with order id: " . $order->getId() . "\n";
            }
        }
    }

    public function cancelOldBuyOrders() {
        $currentPendingOrders = $this->getPendingBuyOrders();

        foreach ($currentPendingOrders as $row) {
            
            // Get the status of the buy order. You can only sell what you got.
            $order = (new \GDAX\Types\Request\Authenticated\Order())->setId($row['order_id']);
            
            /** \GDAX\Types\Response\Authenticated\Order $orderData */
            $orderData = $this->client->getOrder($order);
            
            if ($orderData instanceof \GDAX\Types\Response\Authenticated\Order) {
                $status = $orderData->getStatus(); 
            } else {
                $status = null;
            }

            // Check for old orders and if so cancel them to start over
            if ($status == 'pending' || $status == 'open') {
                $diffInSecs = Carbon::createFromFormat('Y-m-d H:i:s', $row['created_at'])->diffInSeconds(Carbon::now());

                if (Carbon::createFromFormat('Y-m-d H:i:s', $row['created_at'])->diffInSeconds(Carbon::now()) > $this->lifetime) {

                    $order = (new \GDAX\Types\Request\Authenticated\Order())->setId($row['order_id']);
                    $response = $this->client->cancelOrder($order);

                    echo $response->getMessage()."\n";

                    if (isset($response)) {
                        echo "Order " . $row['order_id'] . " is older then " . $this->lifetime . " seconds (" . $diffInSecs . ") and will be deleted\n";
                        $this->updateOrderStatus($row['id'], 'deleted');
                    } else {
                        echo "Could not cancel order " . $row['order_id'] . " for " . $row['amount'] . "\n";
                    }
                }
            }

            if (is_null($status)) {
                echo "Order not found with order id: " . $row['order_id'] . "\n";
                $this->updateOrderStatus($row['id'], $orderData->getMessage());
            }
        }
    }

    protected function placeSellOrder($price) {
        $order = (new \GDAX\Types\Request\Authenticated\Order())
                ->setType(\GDAX\Utilities\GDAXConstants::ORDER_TYPE_LIMIT)
                ->setProductId(\GDAX\Utilities\GDAXConstants::PRODUCT_ID_LTC_EUR)
                ->setSize($this->order_size)
                ->setSide(\GDAX\Utilities\GDAXConstants::ORDER_SIDE_SELL)
                ->setPrice($price)
                ->setPostOnly(true);

        $response = $this->client->placeOrder($order);
        if (isset($response) && $response->getId()) {
            return $response->getId();
        } else {
            echo "Order not placed because : " . $response->getMessage() . "\n";
            return false;
        }
    }

    public function sell() {
        $startPrice = $this->getCurrentPrice();
        $currentPendingOrders = $this->getOrdersOpenBuys();

        $n = 1;
        foreach ($currentPendingOrders as $row) {
            // Get the status of the buy order. You can only sell what you got.
            $order = (new \GDAX\Types\Request\Authenticated\Order())->setId($row['order_id']);
            $orderData = $this->client->getOrder($order);

            /** \GDAX\Types\Response\Authenticated\Order $orderData */
            $status = $orderData->getStatus();

            if ($status == 'done') {
                $buyprice = $row['amount'];
                $sellPrice = $buyprice + 0.01 + $this->spread;
                if ($startPrice > $sellPrice) {
                    $sellPrice = $startPrice + ($n * 0.05);
                }
                $sellPrice = number_format($sellPrice, 2);

                echo 'Sell ' . $this->order_size . ' for ' . $sellPrice . "\n";

                if ($startPrice < $this->sellingTreshold) {
                    printf("Reached sell treshold %s  [%s] so no selling for now\n", $this->sellingTreshold, $startPrice);
                    continue;
                }

                $order_id = $this->placeSellOrder($sellPrice);


                if ($order_id) {
                    $this->insertOrder('sell', $order_id, $sellPrice, 'open', $row['id']);

                    echo "Updating order status from pending to done: " . $row['order_id'] . "\n";
                    $this->updateOrderStatus($row['id'], $status);
                }
            } else {
                echo "Order not done " . $row['order_id'] . "\n";
            }
        }
    }

    public function getOpenOrders() {
        $lowestSellPrice = 1000.0;

        $listOrders = (new \GDAX\Types\Request\Authenticated\ListOrders())
                ->setStatus(\GDAX\Utilities\GDAXConstants::ORDER_STATUS_OPEN)
                ->setProductId(\GDAX\Utilities\GDAXConstants::PRODUCT_ID_LTC_EUR);

        $orders = $this->client->getOrders($listOrders);
        foreach ($orders as $order) {
            $price = $order->getPrice();
            if ($order->getSide() == 'sell') {
                if ($price < $lowestSellPrice) {
                    $lowestSellPrice = $price;
                }
            } else {
                $this->pendingBuyPrices[] = $price;
            }
        }

        $restOrders = $this->max_orders - count($orders);
        echo "Maximum number of orders left to place: " . $restOrders . " of " . $this->max_orders . "\n";
        echo "Lowest sellprice " . $lowestSellPrice . "\n";

        return [$restOrders, $lowestSellPrice];
    }

    protected function placeBuyOrder($price) {
        $order = (new \GDAX\Types\Request\Authenticated\Order())
                ->setType(\GDAX\Utilities\GDAXConstants::ORDER_TYPE_LIMIT)
                ->setProductId(\GDAX\Utilities\GDAXConstants::PRODUCT_ID_LTC_EUR)
                ->setSize($this->order_size)
                ->setSide(\GDAX\Utilities\GDAXConstants::ORDER_SIDE_BUY)
                ->setPrice($price)
                ->setPostOnly(true);

        $response = $this->client->placeOrder($order);

        if (isset($response) && $response->getId()) {
            return $response->getId();
        } else {
            echo "Order not placed because : " . $response->getMessage() . "\n";
            return false;
        }
    }

    public function buy($overrideMaxOrders = 0) {
        list($restOrders, $lowestSellPrice) = $this->getOpenOrders();

        if ($this->max_orders_per_run > $restOrders) {
            $ordersToPlace = $this->max_orders_per_run;
        } else {
            $ordersToPlace = $restOrders;
        }

        $startPrice = $this->getCurrentPrice();

        if ($startPrice > $this->buyingTreshold || $startPrice < $this->sellingTreshold) {
            printf("Treshold reached %s  [%s]  %s so no buying for now\n", $this->sellingTreshold, $startPrice, $this->buyingTreshold);
            return;
        }

        if ($overrideMaxOrders > 0) {
            $restOrders = $overrideMaxOrders;
        }

        for ($i = 1; $i <= $restOrders; $i++) {
            // for buys
            $buyPrice = $startPrice - 0.01 - $i * $this->spread;
            $buyPrice = number_format($buyPrice, 2);

            if ($buyPrice < $lowestSellPrice) {
                echo 'Buy ' . $this->order_size . ' for ' . $buyPrice . "\n";

                $order_id = $this->placeBuyOrder($buyPrice);

                if ($order_id) {
                    $this->insertOrder('buy', $order_id, $buyPrice);
                } else {
                    echo "Order not placed for " . $buyPrice . "\n";
                }
            } else {
                echo "We have open sells that will cross the buys and that is not allowed:" . $buyPrice . "\n";
            }
        }
    }

    public function run() {
        $this->deleteOrdersWithoutOrderId();
         
        $this->actualize();
        $this->actualizeSells();
        $this->sell();
        
        
        $this->actualizeBuys();
        $this->cancelOldBuyOrders();
        $this->buy();
        

        echo "\nDONE\n";
    }

}
