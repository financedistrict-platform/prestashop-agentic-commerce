<?php

namespace FD\PrismUcp\Catalog;

use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Ucp\Formatter;
use FD\PrismUcp\Ucp\UcpError;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * UCP catalog search + lookup over PrestaShop products. Ported from
 * FD_UCP_Catalog_Controller. Operates in the resolved shop's context.
 */
final class CatalogService
{
    public function __construct(private \Context $context)
    {
    }

    /**
     * @param array<string,mixed> $body
     */
    public function search(array $body): Response
    {
        $query = trim((string) ($body['query'] ?? ''));
        $limit = min(max((int) ($body['limit'] ?? 10), 1), 50);
        $offset = max((int) ($body['offset'] ?? 0), 0);
        $idLang = (int) $this->context->language->id;
        $currencyIso = $this->context->currency->iso_code;
        $link = $this->context->link;

        $products = [];
        $total = 0;

        if ($query !== '') {
            $results = \Product::searchByName($idLang, $query) ?: [];
            $total = count($results);
            foreach (array_slice($results, $offset, $limit) as $row) {
                $product = new \Product((int) $row['id_product'], false, $idLang);
                if (\Validate::isLoadedObject($product) && $product->active) {
                    $products[] = Formatter::product($product, $idLang, $currencyIso, $link);
                }
            }
        } else {
            $rows = \Product::getProducts($idLang, $offset, $limit, 'date_add', 'DESC', false, true) ?: [];
            $total = (int) \Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop`
                 WHERE `id_shop` = ' . (int) $this->context->shop->id . ' AND `active` = 1'
            );
            foreach ($rows as $row) {
                $product = new \Product((int) $row['id_product'], false, $idLang);
                if (\Validate::isLoadedObject($product)) {
                    $products[] = Formatter::product($product, $idLang, $currencyIso, $link);
                }
            }
        }

        return Response::json(200, [
            'ucp' => [
                'version' => Formatter::UCP_VERSION,
                'status' => 'success',
                'capabilities' => [
                    'dev.ucp.shopping.catalog.search' => [['version' => Formatter::UCP_VERSION]],
                ],
            ],
            'products' => $products,
            'pagination' => [
                'total_count' => $total,
                'has_next_page' => ($offset + $limit) < $total,
            ],
            'messages' => [],
        ]);
    }

    /**
     * @param array<string,mixed> $body
     */
    public function lookup(array $body): Response
    {
        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            return UcpError::response('missing_ids', 'ids array is required', 400);
        }
        // Bound the work per request (matches the search cap) so a giant ids[]
        // can't force thousands of product loads in one call.
        $ids = array_slice($ids, 0, 50);

        $idLang = (int) $this->context->language->id;
        $currencyIso = $this->context->currency->iso_code;
        $link = $this->context->link;

        $products = [];
        foreach ($ids as $id) {
            $product = new \Product((int) $id, false, $idLang);
            if (\Validate::isLoadedObject($product) && $product->active) {
                $products[] = Formatter::product($product, $idLang, $currencyIso, $link);
            }
        }

        return Response::json(200, [
            'ucp' => [
                'version' => Formatter::UCP_VERSION,
                'status' => 'success',
                'capabilities' => [
                    'dev.ucp.shopping.catalog.lookup' => [['version' => Formatter::UCP_VERSION]],
                ],
            ],
            'products' => $products,
            'messages' => [],
        ]);
    }
}
