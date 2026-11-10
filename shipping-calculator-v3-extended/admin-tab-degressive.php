<?php
/**
 * Onglet admin pour la gestion des produits dégressifs
 */

if (!defined('ABSPATH')) {
    exit;
}

function shp_v2_ext_tab_degressive() {
    $degressive = SHP_V2_Extended_Degressive_Products::get_instance();
    $products = $degressive->get_all_degressive_products();
    ?>
    <h2>🎯 Produits Dégressifs au m²</h2>
    
    <div class="notice notice-info">
        <h3>🆕 Nouvelle Fonctionnalité v2.6.0 !</h3>
        <p><strong>Produits avec tarification dégressive au m²</strong></p>
        <p>Prix au m² qui diminue selon la quantité commandée. Commande par multiples de surface/palette.</p>
        <p><strong>Configuration :</strong> Édition produit > Onglet Expédition > Type 2</p>
    </div>
    
    <?php if (empty($products)): ?>
        <div class="notice notice-warning">
            <p><strong>Aucun produit dégressif configuré.</strong></p>
            <p>Pour créer un produit dégressif :</p>
            <ol>
                <li>Aller dans Produits > Modifier un produit</li>
                <li>Onglet "Expédition"</li>
                <li>Section "Type 2 : Produit Dégressif au m²"</li>
                <li>Cocher "Activer tarification dégressive"</li>
                <li>Configurer surface/palette et paliers de prix</li>
                <li>Enregistrer</li>
            </ol>
        </div>
    <?php else: ?>
        <p><strong><?php echo count($products); ?> produit(s) configuré(s)</strong></p>
        
        <table class="widefat">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th>Nom du Produit</th>
                    <th style="text-align: center;">m²/Palette</th>
                    <th style="text-align: center;">Paliers</th>
                    <th style="text-align: right;">Prix Min</th>
                    <th style="text-align: right;">Prix Max</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <?php 
                    $config = $product['config'];
                    $tiers = $config['tiers'];
                    $min_price = !empty($tiers) ? min(array_column($tiers, 'price_per_m2')) : 0;
                    $max_price = !empty($tiers) ? max(array_column($tiers, 'price_per_m2')) : 0;
                    ?>
                    <tr>
                        <td><strong><?php echo $product['id']; ?></strong></td>
                        <td>
                            <strong><?php echo esc_html($product['name']); ?></strong>
                            <br>
                            <a href="<?php echo get_permalink($product['id']); ?>" target="_blank" style="font-size: 12px;">Voir produit →</a>
                        </td>
                        <td style="text-align: center;">
                            <strong><?php echo $config['surface_per_pallet']; ?> m²</strong>
                        </td>
                        <td style="text-align: center;">
                            <?php echo count($tiers); ?> palier(s)
                        </td>
                        <td style="text-align: right;">
                            <?php echo number_format($min_price, 2); ?>€/m²
                        </td>
                        <td style="text-align: right;">
                            <strong><?php echo number_format($max_price, 2); ?>€/m²</strong>
                        </td>
                        <td style="text-align: center;">
                            <a href="<?php echo admin_url('post.php?post=' . $product['id'] . '&action=edit'); ?>" class="button button-small">
                                ✏️ Modifier
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="7" style="padding-left: 40px; background: #f9f9f9;">
                            <details>
                                <summary style="cursor: pointer; color: #2271b1;"><strong>Voir les paliers de prix</strong></summary>
                                <table style="width: 100%; margin-top: 10px;">
                                    <thead>
                                        <tr style="background: #fff;">
                                            <th>Palettes</th>
                                            <th>Surface (m²)</th>
                                            <th style="text-align: right;">Prix/m²</th>
                                            <th style="text-align: right;">Exemple Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tiers as $tier): ?>
                                            <?php 
                                            $min_m2 = $tier['min_pallets'] * $config['surface_per_pallet'];
                                            $max_m2 = $tier['max_pallets'] * $config['surface_per_pallet'];
                                            $example_pallets = $tier['min_pallets'];
                                            $example_m2 = $example_pallets * $config['surface_per_pallet'];
                                            $example_total = $example_m2 * $tier['price_per_m2'];
                                            ?>
                                            <tr>
                                                <td><?php echo $tier['min_pallets']; ?>-<?php echo $tier['max_pallets']; ?></td>
                                                <td><?php echo $min_m2; ?>-<?php echo $max_m2; ?> m²</td>
                                                <td style="text-align: right;"><strong><?php echo number_format($tier['price_per_m2'], 2); ?>€</strong></td>
                                                <td style="text-align: right;">
                                                    <?php echo $example_pallets; ?> pal. (<?php echo $example_m2; ?> m²) = <?php echo number_format($example_total, 2); ?>€
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <h2 style="margin-top: 30px;">💡 Guide Rapide</h2>
    
    <h3>Exemple : Dalle 35 m²/palette</h3>
    <p><strong>Configuration :</strong></p>
    <ul>
        <li>Surface par palette : 35 m²</li>
        <li>Paliers :
            <ul>
                <li>1-2 palettes (35-70 m²) : 20,58€/m²</li>
                <li>3-5 palettes (105-175 m²) : 20,15€/m²</li>
                <li>6+ palettes (210+ m²) : 19,75€/m²</li>
            </ul>
        </li>
    </ul>
    
    <p><strong>Résultat client :</strong></p>
    <ul>
        <li>Commande 140 m² (4 palettes) → Prix : 20,15€/m² → Total : 2 821€</li>
        <li>Commande 280 m² (8 palettes) → Prix : 19,75€/m² → Total : 5 530€</li>
    </ul>
    
    <p><strong>Frais de port :</strong> Calculés automatiquement selon le nombre de palettes</p>
    
    <p style="margin-top: 20px;">
        <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button button-primary">
            ➕ Créer un Produit Dégressif
        </a>
    </p>
    <?php
}
