/**
 * Produits Ballot - Frontend JavaScript v1.2 (SUPER ROBUSTE)
 * Version: 1.2.0
 * Fix: Insertion HTML garantie + Debug complet
 */

jQuery(document).ready(function($) {
    
    // Vérifier si c'est un produit ballot
    if (typeof shp_ballot_params === 'undefined') {
        console.log('⚠️ Pas un produit ballot (shp_ballot_params non défini)');
        return;
    }
    
    console.log('🎯 Ballot Products JS v1.2 chargé', shp_ballot_params);
    
    var productId = shp_ballot_params.product_id;
    var ballotsPerPallet = shp_ballot_params.ballots_per_pallet;
    var tiers = shp_ballot_params.tiers;
    
    // Créer l'interface
    function createBallotInterface() {
        
        console.log('📦 Création interface ballot...');
        
        // Trouver le formulaire
        var $form = $('form.cart');
        
        if ($form.length === 0) {
            console.error('❌ Formulaire form.cart non trouvé');
            return;
        }
        
        console.log('✅ Formulaire trouvé:', $form.length);
        
        // Trouver le conteneur de quantité
        var $qtyContainer = $('.quantity', $form);
        
        if ($qtyContainer.length === 0) {
            console.error('❌ Conteneur .quantity non trouvé');
            return;
        }
        
        console.log('✅ Conteneur quantité trouvé:', $qtyContainer.length);
        
        // Masquer le champ quantité standard
        $qtyContainer.hide();
        
        // Créer le tableau des paliers
        var tableHTML = '<div class="ballot-pricing-table" style="background: #f8f8f8; padding: 20px; margin: 20px 0; border: 2px solid #c71585; border-radius: 8px;">';
        tableHTML += '<h4 style="margin-top: 0; color: #c71585;">📊 Tarifs Dégressifs</h4>';
        tableHTML += '<table style="width: 100%; border-collapse: collapse;">';
        tableHTML += '<thead>';
        tableHTML += '<tr style="background: #c71585; color: white;">';
        tableHTML += '<th style="padding: 10px; text-align: left;">Quantité</th>';
        tableHTML += '<th style="padding: 10px; text-align: right;">Prix/ballot</th>';
        tableHTML += '<th style="padding: 10px; text-align: right;">Ex. Total</th>';
        tableHTML += '</tr>';
        tableHTML += '</thead>';
        tableHTML += '<tbody>';
        
        // Afficher quelques exemples de paliers
        var examplePallets = [1, 2, 4, 10, 33];
        
        examplePallets.forEach(function(nbPal) {
            var qty = nbPal * ballotsPerPallet;
            var pricePerBallot = getPriceForPallets(nbPal);
            
            if (pricePerBallot) {
                var total = qty * pricePerBallot;
                var palText = nbPal === 1 ? '1 palette' : nbPal + ' palettes';
                
                tableHTML += '<tr style="border-bottom: 1px solid #ddd;">';
                tableHTML += '<td style="padding: 10px;">' + qty + ' ballots (' + palText + ')</td>';
                tableHTML += '<td style="padding: 10px; text-align: right; font-weight: bold;">' + formatPrice(pricePerBallot) + '</td>';
                tableHTML += '<td style="padding: 10px; text-align: right;">' + formatPrice(total) + '</td>';
                tableHTML += '</tr>';
            }
        });
        
        tableHTML += '</tbody>';
        tableHTML += '</table>';
        tableHTML += '</div>';
        
        // Créer le sélecteur de quantité + CHAMP HIDDEN DANS LE MÊME BLOC
        var selectorHTML = '<div class="ballot-quantity-selector" style="margin: 20px 0;">';
        selectorHTML += '<label for="ballot_quantity_select" style="display: block; margin-bottom: 10px; font-weight: bold;">Quantité :</label>';
        selectorHTML += '<select id="ballot_quantity_select" style="width: 100%; max-width: 300px; padding: 10px; font-size: 16px; border: 2px solid #c71585; border-radius: 4px;">';
        selectorHTML += '<option value="">Sélectionner...</option>';
        
        // Générer les options
        tiers.forEach(function(tier) {
            for (var pal = tier.min_pallets; pal <= tier.max_pallets; pal++) {
                var qty = pal * ballotsPerPallet;
                var palText = pal === 1 ? '1 palette' : pal + ' palettes';
                selectorHTML += '<option value="' + qty + '">' + qty + ' ballots (' + palText + ')</option>';
                
                // Limiter à 33 palettes max dans le dropdown
                if (pal >= 33) break;
            }
        });
        
        selectorHTML += '</select>';
        selectorHTML += '<input type="hidden" id="ballot_quantity_hidden" name="ballot_quantity" value="">';
        selectorHTML += '</div>';
        
        // Créer la zone de récapitulatif
        var summaryHTML = '<div class="ballot-summary" style="background: white; padding: 20px; margin: 20px 0; border: 2px solid #2271b1; border-radius: 8px; display: none;">';
        summaryHTML += '<div class="ballot-summary-content"></div>';
        summaryHTML += '</div>';
        
        // ✅ INSERTION ROBUSTE : Directement AVANT le conteneur quantité
        var fullHTML = tableHTML + selectorHTML + summaryHTML;
        
        console.log('📝 HTML créé, longueur:', fullHTML.length);
        
        // Insérer AVANT le conteneur quantité (méthode la plus fiable)
        $qtyContainer.before(fullHTML);
        
        console.log('✅ HTML inséré avant .quantity');
        
        // ✅ VÉRIFIER QUE LES ÉLÉMENTS SONT BIEN CRÉÉS
        var $select = $('#ballot_quantity_select');
        var $hidden = $('#ballot_quantity_hidden');
        
        console.log('🔍 Vérification éléments:');
        console.log('- Select trouvé:', $select.length);
        console.log('- Hidden trouvé:', $hidden.length);
        console.log('- Select dans form:', $select.closest('form').length);
        console.log('- Hidden dans form:', $hidden.closest('form').length);
        
        if ($select.length === 0) {
            console.error('❌ ERREUR : Select non créé !');
            return;
        }
        
        if ($hidden.length === 0) {
            console.error('❌ ERREUR : Hidden non créé !');
            return;
        }
        
        console.log('✅ Tous les éléments créés avec succès');
        
        // ✅ Event: changement de quantité
        $select.on('change', function() {
            var selectedQty = parseInt($(this).val());
            
            console.log('📋 Changement quantité:', selectedQty);
            
            // ✅ METTRE À JOUR LE CHAMP HIDDEN
            $hidden.val(selectedQty || '');
            
            console.log('✅ Hidden mis à jour:', $hidden.val());
            
            if (!selectedQty) {
                $('.ballot-summary').hide();
                return;
            }
            
            calculateAndDisplay(selectedQty);
        });
        
        console.log('✅ Events attachés');
    }
    
    // Obtenir le prix pour un nombre de palettes
    function getPriceForPallets(nbPallets) {
        for (var i = 0; i < tiers.length; i++) {
            var tier = tiers[i];
            if (nbPallets >= tier.min_pallets && nbPallets <= tier.max_pallets) {
                return parseFloat(tier.price_per_ballot);
            }
        }
        return null;
    }
    
    // Calculer et afficher le récapitulatif
    function calculateAndDisplay(qtyBallots) {
        var nbPallets = qtyBallots / ballotsPerPallet;
        var pricePerBallot = getPriceForPallets(nbPallets);
        
        if (!pricePerBallot) {
            $('.ballot-summary').hide();
            return;
        }
        
        var total = qtyBallots * pricePerBallot;
        var palText = nbPallets === 1 ? '1 palette' : nbPallets + ' palettes';
        
        var html = '<h4 style="margin-top: 0; color: #2271b1;">Votre sélection :</h4>';
        html += '<table style="width: 100%;">';
        html += '<tr><td style="padding: 5px;"><strong>Prix unitaire :</strong></td><td style="padding: 5px; text-align: right;">' + formatPrice(pricePerBallot) + ' / ballot</td></tr>';
        html += '<tr><td style="padding: 5px;"><strong>Quantité :</strong></td><td style="padding: 5px; text-align: right;">' + qtyBallots + ' ballots</td></tr>';
        html += '<tr><td style="padding: 5px;"><strong>Palettes :</strong></td><td style="padding: 5px; text-align: right;">' + palText + '</td></tr>';
        html += '<tr style="border-top: 2px solid #2271b1;"><td style="padding: 10px 5px;"><strong style="font-size: 18px;">TOTAL :</strong></td><td style="padding: 10px 5px; text-align: right;"><strong style="font-size: 18px; color: #2271b1;">' + formatPrice(total) + '</strong></td></tr>';
        html += '</table>';
        
        $('.ballot-summary-content').html(html);
        $('.ballot-summary').fadeIn();
        
        // ✅ Mettre à jour le champ quantité masqué WooCommerce
        $('input[name="quantity"]').val(1);
        
        console.log('✅ Récapitulatif affiché - Quantité:', qtyBallots, 'Prix:', pricePerBallot, 'Total:', total);
    }
    
    // Formater le prix (v3.0 : devise dynamique)
    function formatPrice(price) {
        var symbol = shp_ballot_params.currency_symbol || '€';
        return price.toFixed(2).replace('.', ',') + ' ' + symbol;
    }
    
    // Initialiser
    console.log('🚀 Initialisation interface ballot...');
    createBallotInterface();
    
    // ✅ VALIDATION AMÉLIORÉE : S'assurer que la quantité ballot est envoyée
    $('form.cart').on('submit', function(e) {
        var $select = $('#ballot_quantity_select');
        var $hidden = $('#ballot_quantity_hidden');
        
        var selectedQty = $select.val();
        var hiddenQty = $hidden.val();
        
        console.log('📤 Soumission formulaire:');
        console.log('- Select existe:', $select.length);
        console.log('- Hidden existe:', $hidden.length);
        console.log('- Select value:', selectedQty);
        console.log('- Hidden value:', hiddenQty);
        console.log('- Form serialize:', $(this).serialize());
        
        // Vérifier qu'une quantité a été sélectionnée
        if (!selectedQty || !hiddenQty) {
            e.preventDefault();
            alert('Veuillez sélectionner une quantité de ballots.');
            console.error('❌ Aucune quantité sélectionnée');
            return false;
        }
        
        // Vérifier que c'est un multiple
        var qty = parseInt(selectedQty);
        if (qty % ballotsPerPallet !== 0) {
            e.preventDefault();
            alert('La quantité doit être un multiple de ' + ballotsPerPallet + ' ballots.');
            console.error('❌ Quantité invalide:', qty);
            return false;
        }
        
        // ✅ S'assurer une dernière fois que le champ hidden a bien la valeur
        $hidden.val(qty);
        
        console.log('✅ Formulaire validé, envoi de', qty, 'ballots');
        console.log('✅ Données finales:', $(this).serialize());
        
        return true;
    });
    
    // ✅ DEBUG AVANCÉ : Commandes console
    window.ballotDebug = function() {
        console.log('🔍 DEBUG BALLOT:');
        console.log('- Select exists:', $('#ballot_quantity_select').length);
        console.log('- Select value:', $('#ballot_quantity_select').val());
        console.log('- Hidden exists:', $('#ballot_quantity_hidden').length);
        console.log('- Hidden value:', $('#ballot_quantity_hidden').val());
        console.log('- Form exists:', $('form.cart').length);
        console.log('- Form serialize:', $('form.cart').serialize());
    };
    
    console.log('✅ Pour débugger, tape: ballotDebug()');
});
