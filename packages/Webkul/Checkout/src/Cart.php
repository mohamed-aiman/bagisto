<?php

namespace Webkul\Checkout;

use Carbon\Carbon;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Checkout\Repositories\CartItemRepository;
use Webkul\Checkout\Repositories\CartAddressRepository;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Checkout\Models\CartPayment;

/**
 * Facade for all the methods to be implemented in Cart.
 *
 * @author    Prashant Singh <prashant.singh852@webkul.com>
 * @author    Jitendra Singh <jitendra@webkul.com>
 * @copyright 2018 Webkul Software Pvt Ltd (http://www.webkul.com)
 */
class Cart {

    /**
     * CartRepository model
     *
     * @var mixed
     */
    protected $cart;

    /**
     * CartItemRepository model
     *
     * @var mixed
     */
    protected $cartItem;

    /**
     * CustomerRepository model
     *
     * @var mixed
     */
    protected $customer;

    /**
     * CartAddressRepository model
     *
     * @var mixed
     */
    protected $cartAddress;

    /**
     * ProductRepository model
     *
     * @var mixed
     */
    protected $product;

    /**
     * Create a new controller instance.
     *
     * @param  Webkul\Checkout\Repositories\CartRepository        $cart
     * @param  Webkul\Checkout\Repositories\CartItemRepository    $cartItem
     * @param  Webkul\Checkout\Repositories\CartAddressRepository $cartAddress
     * @param  Webkul\Customer\Repositories\CustomerRepository    $customer
     * @param  Webkul\Product\Repositories\ProductRepository      $product
     * @return void
     */
    public function __construct(
        CartRepository $cart,
        CartItemRepository $cartItem,
        CartAddressRepository $cartAddress,
        CustomerRepository $customer,
        ProductRepository $product)
    {
        $this->customer = $customer;

        $this->cart = $cart;

        $this->cartItem = $cartItem;

        $this->cartAddress = $cartAddress;

        $this->product = $product;
    }

    /**
     * Prepare the other data for the product to be success.
     *
     * @param integer $id
     * @param array $data
     *
     * @return array
     */
    public function prepareItemData($productId, $data)
    {
        $product = $this->product->findOneByField('id', $productId);

        // dd($product);

        //Check if the product's information is proper or not.
        if(!isset($data['product']) || !isset($data['quantity'])) {
            session()->flash('error', trans('shop::app.checkout.cart.integrity.missing_fields'));

            return false;
        } else {
            if($product->type == 'configurable' && !isset($data['super_attribute'])) {
                session()->flash('error', trans('shop::app.checkout.cart.integrity.missing_options'));

                return false;
            }
        }

        $child = $childData = null;
        if($product->type == 'configurable') {
            $child = $this->product->findOneByField('id', $data['selected_configurable_option']);
            $childData = [
                'product_id' => $data['selected_configurable_option'],
                'sku' => $child->sku,
                'name' => $child->name,
                'type' => 'simple'
            ];
        }

        $price = ($product->type == 'configurable' ? $child->price : $product->price);

        $parentData = [
            'sku' => $product->sku,
            'product_id' => $productId,
            'quantity' => $data['quantity'],
            'type' => $product->type,
            'name' => $product->name,
            'price' => core()->convertPrice($price),
            'base_price' => $price,
            'total' => core()->convertPrice($price * $data['quantity']),
            'base_total' => $price * $data['quantity'],
            'weight' => $weight = ($product->type == 'configurable' ? $child->weight : $product->weight),
            'total_weight' => $weight * $data['quantity'],
            'base_total_weight' => $weight * $data['quantity']
        ];

        return ['parent' => $parentData, 'child' => $childData];
    }

    /**
     * Add Items in a cart with some cart and item details.
     *
     * @param integer $id
     * @param array   $data
     *
     * @return void
     */
    public function add($id, $data)
    {

        $itemData = $this->prepareItemData($id, $data);

        if(!$itemData) {
            return false;
        }

        if($cart = $this->getCart()) {
            $product = $this->product->find($id);

            $cartItems = $cart->items;

            //check the isset conditions as collection empty object will mislead the condition and errorhandling case.
            if(isset($cartItems) && $cartItems->count()) {
                foreach($cartItems as $cartItem) {
                    if($product->type == "simple") {

                        if($cartItem->product_id == $id) {
                            $prevQty = $cartItem->quantity;
                            $newQty = $data['quantity'];

                            $canBe = $this->canAddOrUpdate($cartItem->id, $prevQty + $newQty);

                            if($canBe == false) {
                                session()->flash('warning', trans('shop::app.checkout.cart.quantity.inventory_warning'));

                                return false;
                            }

                            $cartItem->update([
                                'quantity' => $prevQty + $newQty,
                                'total' => core()->convertPrice($cartItem->price * ($prevQty + $newQty)),
                                'base_total' => $cartItem->price * ($prevQty + $newQty)
                            ]);

                            $this->collectTotals();

                            session()->flash('success', trans('shop::app.checkout.cart.quantity.success'));

                            return true;
                        }
                    } else if($product->type == "configurable") {
                        if($cartItem->type == "configurable") {
                            $temp = $this->cartItem->findOneByField('parent_id', $cartItem->id);
                            if($temp->product_id == $data['selected_configurable_option']) {
                                $child = $temp->child;

                                $parent = $cartItem;
                                $parentPrice = $parent->price;

                                $prevQty = $parent->quantity;
                                $newQty = $data['quantity'];

                                $canBe = $this->canAddOrUpdate($cartItem->child->id, $prevQty + $newQty);

                                if($canBe == false) {
                                    session()->flash('warning', trans('shop::app.checkout.cart.quantity.inventory_warning'));

                                    return false;
                                }

                                $parent->update([
                                    'quantity' => $prevQty + $newQty,
                                    'total' => core()->convertPrice($parentPrice * ($prevQty + $newQty)),
                                    'base_total' => $parentPrice * ($prevQty + $newQty)
                                ]);

                                $this->collectTotals();

                                session()->flash('success', trans('shop::app.checkout.cart.quantity.success'));

                                return true;
                            }
                        }
                    }
                }

                if($product->type == "configurable") {
                    $parent = $cart->items()->create($itemData['parent']);

                    $itemData['child']['parent_id'] = $parent->id;

                    $cart->items()->create($itemData['child']);
                } else if($product->type != "configurable"){
                    $parent = $cart->items()->create($itemData['parent']);
                }

                $this->collectTotals();

                session()->flash('success', trans('shop::app.checkout.cart.item.success'));

                return $cart;
            } else {
                if(isset($cart)) {
                    $this->cart->delete($cart->id);
                } else {
                    return $this->createNewCart($id, $data);
                }
            }
        } else {
            return $this->createNewCart($id, $data);
        }
    }

    /**
     * Create new cart instance with the current item success.
     *
     * @param integer $id
     * @param array   $data
     *
     * @return Booleans
     */
    public function createNewCart($id, $data)
    {
        $itemData = $this->prepareItemData($id, $data);

        //if the item data is not valid to be processed it will be returning false
        if($itemData == false) {
            return false;
        }

        $cartData['channel_id'] = core()->getCurrentChannel()->id;

        //auth user details else they will be set when the customer is guest
        if(auth()->guard('customer')->check()) {
            $cartData['customer_id'] = auth()->guard('customer')->user()->id;
            $cartData['is_guest'] = 0;
            $cartData['customer_first_name'] = auth()->guard('customer')->user()->first_name;
            $cartData['customer_last_name'] = auth()->guard('customer')->user()->last_name;
            $cartData['customer_email'] = auth()->guard('customer')->user()->email;
        } else {
            $cartData['is_guest'] = 1;
        }

        //set the currency column with the respective currency
        $cartData['global_currency_code'] = core()->getBaseCurrencyCode();
        $cartData['base_currency_code'] = core()->getBaseCurrencyCode();
        $cartData['channel_currency_code'] = core()->getBaseCurrencyCode();
        $cartData['cart_currency_code'] = core()->getBaseCurrencyCode();
        //set the cart items and quantity
        $cartData['items_count'] = 1;
        $cartData['items_qty'] = $data['quantity'];

        //create the cart instance in the database
        if($cart = $this->cart->create($cartData)) {
            $itemData['parent']['cart_id'] = $cart->id;
            $product = $this->product->find($id);

            if ($product->type == "configurable") {
                //parent item entry
                $itemData['parent']['additional'] = json_encode($data);

                if($parent = $this->cartItem->create($itemData['parent'])) {
                    //child item entry
                    $itemData['child']['parent_id'] = $parent->id;
                    $itemData['child']['cart_id'] = $cart->id;

                    if($child = $this->cartItem->create($itemData['child'])) {
                        $this->putCart($cart);

                        session()->flash('success', trans('shop::app.checkout.cart.item.success'));

                        $this->collectTotals();

                        return $cart;
                    }
                }
            } else if($product->type != "configurable") {
                if($this->cartItem->create($itemData['parent'])) {
                    $this->putCart($cart);

                    session()->flash('success', trans('shop::app.checkout.cart.item.success'));

                    $this->collectTotals();

                    return $cart;
                }
            }
        }

        session()->flash('error', trans('shop::app.checkout.cart.item.error_add'));

        return false;
    }

    /**
     * This function handles when guest has some of cart products and then logs in.
     *
     * @return Response
     */
    public function mergeCart()
    {
        if(session()->has('cart')) {
            $cart = $this->cart->findOneByField('customer_id', auth()->guard('customer')->user()->id);

            $guestCart = session()->get('cart');

            //when the logged in customer is not having any of the cart instance previously and are active.
            if(!isset($cart)) {
                $guestCart->update([
                    'customer_id' => auth()->guard('customer')->user()->id,
                    'is_guest' => 0,
                    'customer_first_name' => auth()->guard('customer')->user()->first_name,
                    'customer_last_name' => auth()->guard('customer')->user()->last_name,
                    'customer_email' => auth()->guard('customer')->user()->email
                ]);

                session()->forget('cart');

                return true;
            }

            $cartItems = $cart->items;

            $guestCartId = $guestCart->id;

            $guestCartItems = $this->cart->findOneByField('id', $guestCartId)->items;

            foreach($guestCartItems as $key => $guestCartItem) {
                foreach($cartItems as $cartItem) {

                    if($guestCartItem->type == "simple") {
                        if($cartItem->product_id == $guestCartItem->product_id) {
                            $prevQty = $cartItem->quantity;

                            $newQty = $guestCartItem->quantity;

                            $canBe = $this->canAddOrUpdate($cartItem->id, $prevQty + $newQty);

                            if($canBe == false) {
                                $this->cartItem->delete($guestCartItem->id);
                                continue;
                            }

                            $cartItem->update([
                                'quantity' => $prevQty + $newQty,
                                'total' => core()->convertPrice($cartItem->price * ($prevQty + $newQty)),
                                'base_total' => $cartItem->price * ($prevQty + $newQty),
                                'total_weight' => $cartItem->weight * ($prevQty + $newQty),
                                'base_total_weight' => $cartItem->weight * ($prevQty + $newQty)
                            ]);

                            $guestCartItems->forget($key);
                            $this->cartItem->delete($guestCartItem->id);
                        }
                    } else if($guestCartItem->type == "configurable" && $cartItem->type == "configurable") {
                        $guestCartItemChild = $guestCartItem->child;

                        $cartItemChild = $cartItem->child;

                        if($guestCartItemChild->product_id == $cartItemChild->product_id) {
                            $prevQty = $guestCartItem->quantity;
                            $newQty = $cartItem->quantity;

                            $canBe = $this->canAddOrUpdate($cartItem->child->id, $prevQty + $newQty);

                            if($canBe == false) {
                                $this->cartItem->delete($guestCartItem->id);
                                continue;
                            }

                            $cartItem->update([
                                'quantity' => $prevQty + $newQty,
                                'total' => core()->convertPrice($cartItem->price * ($prevQty + $newQty)),
                                'base_total' => $cartItem->price * ($prevQty + $newQty),
                                'total_weight' => $cartItem->weight * ($prevQty + $newQty),
                                'base_total_weight' => $cartItem->weight * ($prevQty + $newQty)
                            ]);

                            $guestCartItems->forget($key);

                            //child will be deleted first
                            // $this->cartItem->delete($guestCartItemChild->id);

                            //then parent will also delete the child if any
                            $this->cartItem->delete($guestCartItem->id);
                        }
                    }
                }
            }

            //now handle the products that are not deleted.
            foreach($guestCartItems as $guestCartItem) {

                if($guestCartItem->type == "configurable") {
                    $guestCartItem->update(['cart_id' => $cart->id]);

                    $guestCartItem->child->update(['cart_id' => $cart->id]);
                } else{
                    $guestCartItem->update(['cart_id' => $cart->id]);
                }
            }

            //delete the guest cart instance.
            $this->cart->delete($guestCartId);

            //forget the guest cart instance
            session()->forget('cart');

            $this->collectTotals();

            return redirect()->back();
        } else {
            return redirect()->back();
        }
    }

    /**
     * Update the cart on
     * cart checkout page
     */
    public function update($itemIds)
    {
        if($cart = $this->getCart()) {
            $items = $cart->items;

            foreach($items as $item) {
                foreach($itemIds['qty'] as $id => $quantity) {
                    if($id == $item->id) {
                        if($item->type == "configurable") {
                            $canBe = $this->canAddOrUpdate($item->child->id, $quantity);
                        } else {
                            $canBe = $this->canAddOrUpdate($id, $quantity);
                        }

                        if($canBe == false) {
                            session()->flash('warning', trans('shop::app.checkout.cart.quantity.inventory_warning'));

                            return $cart;
                        }

                        $item->update(['quantity' => $quantity]);

                        $this->collectTotals();
                    }
                }
            }

            session()->flash('success', trans('shop::app.checkout.cart.quantity.success'));
        }

        return $cart;
    }

    /**
     * Remove the item from the cart
     *
     * @return response
     */
    public function removeItem($itemId)
    {
        if($cart = $this->getCart()) {
            $this->cartItem->delete($itemId);

            $this->collectTotals();

            //delete the cart instance if no items are there
            if($cart->items()->get()->count() == 0) {
                $this->cart->delete($cart->id);

                session()->forget('cart');

                session()->flash('success', trans('shop::app.checkout.cart.quantity.success_remove'));
            }
        }
    }

    /**
     * Method to check if the product is available and its required quantity
     * is available or not in the inventory sources.
     *
     * @param integer $id
     *
     * @return Array
     */
    public function canAddOrUpdate($itemId, $quantity)
    {
        if ($quantity < 1) {
            session()->flash('warning', trans('shop::app.checkout.cart.quantity.warning'));

            return redirect()->back();
        }

        $item = $this->cartItem->findOneByField('id', $itemId);

        if($item->product->haveSufficientQuantity($quantity)) {
            return true;
        }

        return false;
    }

    /**
     * Save cart
     *
     * @return mixed
     */
    public function putCart($cart)
    {
        if(!auth()->guard('customer')->check()) {
            session()->put('cart', $cart);
        }
    }

    /**
     * Returns cart
     *
     * @return mixed
     */
    public function getCart()
    {
        $cart = null;

        if(auth()->guard('customer')->check()) {
            $cart = $this->cart->findOneByField('customer_id', auth()->guard('customer')->user()->id);
        } elseif(session()->has('cart')) {
            $cart = $this->cart->find(session()->get('cart')->id);
        }

        return $cart && $cart->is_active ? $cart : null;
    }

    /**
     * Returns cart details in array
     *
     * @return array
     */
    public function toArray()
    {
        $cart = $this->getCart();

        $data = $cart->toArray();

        $data['shipping_address'] = current($data['shipping_address']);

        $data['billing_address'] = current($data['billing_address']);

        $data['selected_shipping_rate'] = $cart->selected_shipping_rate->toArray();

        return $data;
    }

    /**
     * Returns cart
     *
     * @return Mixed
     */
    public function getItemAttributeOptionDetails($item)
    {
        $data = [];

        $labels = [];

        foreach($item->product->super_attributes as $attribute) {
            $option = $attribute->options()->where('id', $item->child->product->{$attribute->code})->first();

            $data['attributes'][$attribute->code] = [
                'attribute_name' => $attribute->name,
                'option_label' => $option->label,
            ];

            $labels[] = $attribute->name . ' : ' . $option->label;
        }

        $data['html'] = implode(', ', $labels);

        return $data;
    }

    /**
     * Save customer address
     *
     * @return Mixed
     */
    public function saveCustomerAddress($data)
    {
        if(!$cart = $this->getCart())
            return false;

        $billingAddress = $data['billing'];
        $shippingAddress = $data['shipping'];
        $billingAddress['cart_id'] = $shippingAddress['cart_id'] = $cart->id;

        if($billingAddressModel = $cart->billing_address) {
            $this->cartAddress->update($billingAddress, $billingAddressModel->id);

            if($shippingAddress = $cart->shipping_address) {
                if(isset($billingAddress['use_for_shipping']) && $billingAddress['use_for_shipping']) {
                    $this->cartAddress->update($billingAddress, $shippingAddress->id);
                } else {
                    $this->cartAddress->update($shippingAddress, $shippingAddress->id);
                }
            } else {
                if(isset($billingAddress['use_for_shipping']) && $billingAddress['use_for_shipping']) {
                    $this->cartAddress->create(array_merge($billingAddress, ['address_type' => 'shipping']));
                } else {
                    $this->cartAddress->create(array_merge($shippingAddress, ['address_type' => 'shipping']));
                }
            }
        } else {
            $this->cartAddress->create(array_merge($billingAddress, ['address_type' => 'billing']));

            if(isset($billingAddress['use_for_shipping']) && $billingAddress['use_for_shipping']) {
                $this->cartAddress->create(array_merge($billingAddress, ['address_type' => 'shipping']));
            } else {
                $this->cartAddress->create(array_merge($shippingAddress, ['address_type' => 'shipping']));
            }
        }

        return true;
    }

    /**
     * Save shipping method for cart
     *
     * @param string $shippingMethodCode
     * @return Mixed
     */
    public function saveShippingMethod($shippingMethodCode)
    {
        if(!$cart = $this->getCart())
            return false;

        $cart->shipping_method = $shippingMethodCode;
        $cart->save();

        // foreach($cart->shipping_rates as $rate) {
        //     if($rate->method != $shippingMethodCode) {
        //         $rate->delete();
        //     }
        // }

        return true;
    }

    /**
     * Save payment method for cart
     *
     * @param string $payment
     * @return Mixed
     */
    public function savePaymentMethod($payment)
    {
        if(!$cart = $this->getCart())
            return false;

        if($cartPayment = $cart->payment)
            $cartPayment->delete();

        $cartPayment = new CartPayment;

        $cartPayment->method = $payment['method'];
        $cartPayment->cart_id = $cart->id;
        $cartPayment->save();

        return $cartPayment;
    }

    /**
     * Updates cart totals
     *
     * @return void
     */
    public function collectTotals()
    {
        if(!$cart = $this->getCart())
            return false;

        $this->calulateItemsTax();

        $cart->grand_total = 0;
        $cart->base_grand_total = 0;

        $cart->sub_total = 0;
        $cart->base_sub_total = 0;

        $cart->tax_total = 0;
        $cart->base_tax_total = 0;

        foreach ($cart->items()->get() as $item) {
            $cart->grand_total = (float) $cart->grand_total + $item->total;
            $cart->base_grand_total = (float) $cart->base_grand_total + $item->base_total;

            $cart->sub_total = (float) $cart->sub_total + $item->total;
            $cart->base_sub_total = (float) $cart->base_sub_total + $item->base_total;

            $cart->tax_total = (float) $cart->tax_total + $item->tax_amount;
            $cart->base_tax_total = (float) $cart->base_tax_total + $item->base_tax_amount;
        }

        if($shipping = $cart->selected_shipping_rate) {
            $cart->grand_total = (float) $cart->grand_total + $shipping->price;
            $cart->base_grand_total = (float) $cart->base_grand_total + $shipping->base_price;
        }

        $quantities = 0;
        foreach($cart->items as $item) {
            $quantities = $quantities + $item->quantity;
        }

        $cart->items_count = $cart->items->count();

        $cart->items_qty = $quantities;

        $cart->save();
    }

    /**
     * Calculates cart items tax
     *
     * @return void
    */
    public function calulateItemsTax()
    {
        $cart = $this->getCart();

        foreach ($cart->items()->get() as $item) {
            $product = $item->product;
        }
    }

    /**
     * Checks if cart has any error
     *
     * @return boolean
    */
    public function hasError()
    {
        if(!$this->getCart())
            return true;

        if(!$this->isItemsHaveSufficientQuantity())
            return true;

        return false;
    }

    /**
     * Checks if all cart items have sufficient quantity.
     *
     * @return boolean
     */
    public function isItemsHaveSufficientQuantity()
    {
        foreach ($this->getCart()->items as $item) {
            if(!$this->isItemHaveQuantity($item))
                return false;
        }

        return true;
    }

    /**
     * Checks if all cart items have sufficient quantity.
     *
     * @return boolean
     */
    public function isItemHaveQuantity($item)
    {
        $product = $item->type == 'configurable' ? $item->child->product : $item->product;

        if(!$product->haveSufficientQuantity($item->quantity))
            return false;

        return true;
    }

    /**
     * Deactivates current cart
     *
     * @return void
     */
    public function deActivateCart()
    {
        if($cart = $this->getCart()) {
            $this->cart->update(['is_active' => false], $cart->id);

            if(session()->has('cart')) {
                session()->forget('cart');
            }
        }
    }

    /**
     * Validate order before creation
     *
     * @return array
     */
    public function prepareDataForOrder()
    {
        $data = $this->toArray();

        $finalData = [
            'customer_id' => $data['customer_id'],
            'is_guest' => $data['is_guest'],
            'customer_email' => $data['customer_email'],
            'customer_first_name' => $data['customer_first_name'],
            'customer_last_name' => $data['customer_last_name'],
            'customer' => auth()->guard('customer')->check() ? auth()->guard('customer')->user : null,

            'shipping_method' => $data['selected_shipping_rate']['method'],
            'shipping_title' => $data['selected_shipping_rate']['carrier_title'] . ' - ' . $data['selected_shipping_rate']['method_title'],
            'shipping_description' => $data['selected_shipping_rate']['method_description'],
            'shipping_amount' => $data['selected_shipping_rate']['price'],
            'base_shipping_amount' => $data['selected_shipping_rate']['base_price'],

            'total_item_count' => $data['items_count'],
            'total_qty_ordered' => $data['items_qty'],
            'base_currency_code' => $data['base_currency_code'],
            'channel_currency_code' => $data['channel_currency_code'],
            'order_currency_code' => $data['cart_currency_code'],
            'grand_total' => $data['grand_total'],
            'base_grand_total' => $data['base_grand_total'],
            'sub_total' => $data['sub_total'],
            'base_sub_total' => $data['base_sub_total'],
            'tax_amount' => $data['tax_total'],
            'base_tax_amount' => $data['base_tax_total'],

            'shipping_address' => array_except($data['shipping_address'], ['id', 'cart_id']),
            'billing_address' => array_except($data['billing_address'], ['id', 'cart_id']),
            'payment' => array_except($data['payment'], ['id', 'cart_id']),

            'channel' => core()->getCurrentChannel(),
        ];

        foreach($data['items'] as $item) {
            $finalData['items'][] = $this->prepareDataForOrderItem($item);
        }

        return $finalData;
    }

    /**
     * Prepares data for order item
     *
     * @return array
     */
    public function prepareDataForOrderItem($data)
    {
        $finalData = [
            'product' => $this->product->find($data['product_id']),
            'sku' => $data['sku'],
            'type' => $data['type'],
            'name' => $data['name'],
            'weight' => $data['weight'],
            'total_weight' => $data['total_weight'],
            'qty_ordered' => $data['quantity'],
            'price' => $data['price'],
            'base_price' => $data['base_price'],
            'total' => $data['total'],
            'base_total' => $data['base_total'],
            'tax_percent' => $data['tax_percent'],
            'tax_amount' => $data['tax_amount'],
            'base_tax_amount' => $data['base_tax_amount'],
            'additional' => $data['additional'],
        ];

        if(isset($data['child']) && $data['child']) {
            $finalData['child'] = $this->prepareDataForOrderItem($data['child']);
        }

        return $finalData;
    }
}