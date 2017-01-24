<?php
namespace Stentle\LaravelWebcore\Models;

use Illuminate\Support\Facades\Session;
use Stentle\LaravelWebcore\Business\Localization;
use Stentle\LaravelWebcore\Facades\ClientHttp;
use Stentle\LaravelWebcore\Http\RestModel;

class Cart extends RestModel
{
    protected $resource = 'carts';
    public $productCartList;
    public $itemQuantity;
    public $status;
    public $currency;
    public $currencySymbol;
    public $totals;
    public $country;
    public $message;
    public $settings;

    public static function getCartFromSession()
    {
        $country_active = Localization::getCountryRegionActive();
        $carts = Session::get('carts');
        if (is_array($carts) && isset($carts[$country_active])) {
            self::activeCartInSession($carts[$country_active]['id']);
            return $carts[$country_active];
        }

        return null;
    }

    public static function deleteCartFromSession($cart_id)
    {
        $carts = Session::get('carts');
        if (is_array($carts)) {
            foreach ($carts as $country => $cart) {
                if ($cart['id'] == $cart_id) {
                    unset($carts[$country]);
                    Session::put('carts', $carts);
                    if (self::getCartIDActive() == $cart_id) {
                        self::resetCartActive();
                    }

                    return true;
                }
            }
        }
        return false;
    }

    public static function storeCartInSession($cart)
    {
        $country_active = Localization::getCountryRegionActive();
        $carts = Session::get('carts');
        if (!is_array($carts))
            $carts = [];
        $carts[$country_active] = $cart->getInfo();
        Session::put('carts', $carts);
        Cart::activeCartInSession($cart->id);
    }

    public static function switchCartInSession($country_region)
    {
        $carts = Session::get('carts');
        if (is_array($carts)) {
            foreach ($carts as $key => $cart) {
                if ($key == $country_region) {
                    Cart::activeCartInSession($cart->id);
                    return true;
                }
            }
        }

        self::resetCartActive();

        return false;
    }


    private static function activeCartInSession($cart_id)
    {
        setcookie('cart_id', $cart_id, time() + env('SESSION_DURATION') * 60, '/');
        $_COOKIE['cart_id'] = $cart_id;
    }

    public static function getCartIDActive()
    {
        return $_COOKIE['cart_id'];
    }

    public static function resetCartActive()
    {
        setcookie('cart_id', null, time() - 3600, '/');
        $_COOKIE['cart_id'] = null;
    }

    /**Si occupa di creare un carrello con un prodotto specifico e dei settings
     * @param $product_id
     * @param array $settings
     * @param int $quantity
     * @return bool|Cart
     */
    public static function create($product_id, $settings = [], $quantity = 1)
    {

        $cart = new Cart();

        $cart->productCartList = array();
        $cart->productCartList[] = array('id' => $product_id, 'requestedQuantity' => $quantity);
        if (count($settings) > 0)
            $cart->settings = $settings;
        if ($cart->save() && !empty($cart->id)) {
            Cart::storeCartInSession($cart);
            return $cart;
        } else {
            return false;
        }
    }

    /**
     * Esegue il checkout con paypal.
     * Restituisce la risposta dell'api.
     * Paypal offre la possibilità di eseguire il pagamento diretto o con prelievo automatico in un momento successivo($preapproval=true).
     * Nell'ultimo caso  se l'utente non ha mai fornito l'autorizzazione ai prelievi automatici l'api fornisce un intent per fare la redirect a paypal,
     * altrimenti fornisce la risposta del carrello con il suo status aggiornato. Nell'ultimo caso l'oggetto stesso viene aggiornato con la risposta.
     * @param $settings
     * @return bool|mixed
     */
    public function checkout($settings)
    {

        if ($this->id != null) {
            $options['json'] = $settings;

            $response = ClientHttp::post($this->resource . '/' . $this->id . '/checkout', $options);

            if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
                $json = json_decode($response->getBody()->getContents(), true);
                if (isset($json['data']))
                    $this->setInfo($json['data']);
                return $json;
            } else {
                return false;
            }
        } else
            return false;

    }

    /**
     * Changes the item quantity of the provided productId
     * @param $productId The product id of the item
     * @param $qt the new requested quantity
     */
    public function changeItemQuantity($productId, $qt)
    {
        $cartFromSession = Cart::getCartFromSession();

        $response = ClientHttp::patch('carts/' . $cartFromSession['id'],
            ['json' => ['id' => $productId, 'requestedQuantity' => $qt]]);

        $cart = new Cart();
        $cart = $cart->setInfo($response);

        Cart::storeCartInSession($cart);

        return $cart;
    }


    /**
     * Esegue il checkout con paypal.
     * Restituisce la risposta dell'api.
     * Paypal offre la possibilità di eseguire il pagamento diretto o con prelievo automatico in un momento successivo($preapproval=true).
     * Nell'ultimo caso  se l'utente non ha mai fornito l'autorizzazione ai prelievi automatici l'api fornisce un intent per fare la redirect a paypal,
     * altrimenti fornisce la risposta del carrello con il suo status aggiornato. Nell'ultimo caso l'oggetto stesso viene aggiornato con la risposta.
     * @param $url_success  La url che paypal direzionerà l'utente dopo il corretto pagamento
     * @param $url_failure   La url che paypal direzionerà l'utente in caso il pagamento fallisce
     * @param $preapproval se true, all'utente verrà richiesta un'autorizzazione ai prelievi automatici. Se false, si procede al pagamento diretto.
     * @param string $mode paypal ha due modalità express checkout e adaptive. Stentle attualmente utilizza quest'ultima modalità.
     */
    public function checkoutWithPaypal($url_success, $url_failure, $preapproval, $mode = 'adaptive')
    {

        $config['paymentService'] = 'paypal';
        $config['paypalMode'] = $mode;
        $config['paypalCancelUrl'] = $url_failure;
        $config['paypalReturnUrl'] = $url_success;
        $config['paypalPreapproval'] = $preapproval;
        return $this->checkout($config);
    }

    /** Questa chiamata va eseguita una volta che è stato effettuato il pagamento con paypal e per forzare l'api a fare il checkout finale.
     * @param $preapproval
     * @param string $mode
     * @return bool|mixed
     */
    public function completeCheckoutWithPaypal($preapproval = 'true', $mode = 'adaptive')
    {
        if ($this->status == 'CART_PAYING') {
            $config['paymentService'] = 'paypal';
            $config['paypalMode'] = $mode;
            $config['paypalCancelUrl'] = '';
            $config['paypalReturnUrl'] = '';
            $config['paypalPreapproval'] = $preapproval;
            return $this->checkout($config);
        } else {
            return false;
        }
    }

    public function setShippingAddress()
    {

        if ($this->id != null) {

        } else {
            throw new \Exception("cart_id is notdefined");
        }
    }

    public function setBillingAddress()
    {
        if ($this->id != null) {

        } else {
            throw new \Exception("cart_id is notdefined");
        }
    }
}
