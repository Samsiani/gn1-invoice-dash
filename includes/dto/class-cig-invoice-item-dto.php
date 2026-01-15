<?php
/**
 * Invoice Item Data Transfer Object
 * Represents a single invoice item with status tracking
 *
 * @package CIG
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Invoice_Item_DTO {

    /** @var int */
    public $id;

    /** @var int */
    public $invoice_id;

    /** @var int */
    public $product_id;

    /** @var string */
    public $sku;

    /** @var string */
    public $name;

    /** @var string */
    public $brand;

    /** @var string */
    public $desc;

    /** @var string */
    public $image;

    /** @var float */
    public $qty;

    /** @var float */
    public $price;

    /** @var float */
    public $total;

    /** @var string */
    public $warranty;

    /** @var int */
    public $reservation_days;

    /** @var string */
    public $status;

    /**
     * Create DTO from array data
     *
     * @param array $data Item data array
     * @return self
     */
    public static function from_array(array $data): self {
        $dto = new self();
        
        $dto->id = intval($data['id'] ?? 0);
        $dto->invoice_id = intval($data['invoice_id'] ?? 0);
        $dto->product_id = intval($data['product_id'] ?? 0);
        $dto->sku = sanitize_text_field($data['sku'] ?? '');
        $dto->name = sanitize_text_field($data['name'] ?? '');
        $dto->brand = sanitize_text_field($data['brand'] ?? '');
        $dto->desc = sanitize_textarea_field($data['desc'] ?? '');
        $dto->image = esc_url_raw($data['image'] ?? '');
        $dto->qty = floatval($data['qty'] ?? 0);
        $dto->price = floatval($data['price'] ?? 0);
        $dto->total = floatval($data['total'] ?? ($dto->qty * $dto->price));
        $dto->warranty = sanitize_text_field($data['warranty'] ?? '');
        $dto->reservation_days = intval($data['reservation_days'] ?? 0);
        $dto->status = sanitize_text_field($data['status'] ?? 'sold');

        return $dto;
    }

    /**
     * Convert DTO to array for database storage
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'brand' => $this->brand,
            'desc' => $this->desc,
            'image' => $this->image,
            'qty' => $this->qty,
            'price' => $this->price,
            'total' => $this->total,
            'warranty' => $this->warranty,
            'reservation_days' => $this->reservation_days,
            'status' => $this->status
        ];
    }
}
