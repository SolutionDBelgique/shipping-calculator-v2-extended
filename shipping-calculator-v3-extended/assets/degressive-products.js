jQuery(document).ready(function($) {
    'use strict';
    
    // Vérifier que nous sommes sur une page produit dégressif
    if (typeof shp_degressive_params === 'undefined') {
        return;
    }
    
    var productId = shp_degressive_params.product_id;
    var ajaxUrl = shp_degressive_params.ajax_url;
    var nonce = shp_degressive_params.nonce;
    
    // Créer l'interface de sélection
    function createDegressiveInterface() {
        var $form = $('form.cart');
        
        if ($form.length === 0) {
            return;
        }
        
        // Remplacer le champ quantité standard par notre sélecteur
        var $qtyInput = $form.find('input[name="quantity"]');
        
        if ($qtyInput.length > 0) {
            // Créer le conteneur
            var html = '<div class="degressive-selector" style="margin: 20px 0;">';
            html += '<input type="hidden" name="quantity" value="1">'; // IMPORTANT : garder quantity=1
            html += '<label for="quantity_m2" style="display: block; font-weight: bold; margin-bottom: 10px;">Quantité :</label>';
            html += '<select name="quantity_m2" id="quantity_m2" style="width: 100%; padding: 10px; font-size: 16px;">';
            html += '<option value="">-- Sélectionner --</option>';
            html += '</select>';
            html += '</div>';
            
            html += '<div class="degressive-summary" style="background: #f0f6fc; border: 2px solid #2271b1; padding: 15px; margin: 20px 0; border-radius: 5px;">';
            html += '<div style="margin-bottom: 10px;"><strong>Prix unitaire :</strong> <span id="price-per-m2">--</span> / m²</div>';
            html += '<div style="margin-bottom: 10px;"><strong>Quantité :</strong> <span id="quantity-display">--</span></div>';
            html += '<div style="font-size: 20px; font-weight: bold; color: #2271b1;"><strong>TOTAL :</strong> <span id="total-price">--</span></div>';
            html += '</div>';
            
            html += '<p style="color: #666; font-size: 13px; font-style: italic;">ℹ️ Prix dégressif selon quantité commandée</p>';
            
            // Remplacer le champ quantité
            $qtyInput.closest('.quantity').replaceWith(html);
            
            // Charger les options via AJAX
            loadQuantityOptions();
        }
    }
    
    // Charger les options de quantité
    function loadQuantityOptions() {
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_degressive_options',
                product_id: productId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success && response.data.options) {
                    var $select = $('#quantity_m2');
                    $.each(response.data.options, function(m2, label) {
                        $select.append('<option value="' + m2 + '">' + label + '</option>');
                    });
                }
            }
        });
    }
    
    // Calculer le prix quand la sélection change
    $(document).on('change', '#quantity_m2', function() {
        var m2 = $(this).val();
        
        if (!m2 || m2 == '') {
            $('#price-per-m2').text('--');
            $('#quantity-display').text('--');
            $('#total-price').text('--');
            $('.single_add_to_cart_button').prop('disabled', true);
            return;
        }
        
        // Afficher un loader
        $('#total-price').html('<span>Calcul...</span>');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'calculate_degressive_price',
                product_id: productId,
                quantity_m2: m2,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // Mettre à jour l'affichage
                    var currencySymbol = shp_degressive_params.currency_symbol || '€';
                    $('#price-per-m2').text(data.price_per_m2 + ' ' + currencySymbol);
                    $('#quantity-display').text(m2 + ' m² (' + data.nb_palettes + ' palette' + (data.nb_palettes > 1 ? 's' : '') + ')');
                    $('#total-price').html(data.total);
                    
                    // Mettre à jour le prix principal si présent
                    if ($('.summary .price .amount').length) {
                        $('.summary .price .amount').html(data.total);
                    }
                    
                    // Activer le bouton d'ajout au panier
                    $('.single_add_to_cart_button').prop('disabled', false);
                } else {
                    alert('Erreur de calcul. Veuillez réessayer.');
                }
            },
            error: function() {
                $('#total-price').text('Erreur');
                alert('Erreur de connexion. Veuillez réessayer.');
            }
        });
    });
    
    // Désactiver le bouton d'ajout au panier par défaut
    $('.single_add_to_cart_button').prop('disabled', true);
    
    // Initialiser l'interface
    createDegressiveInterface();
});
