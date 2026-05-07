<?php
add_action('acf/include_fields', function () {
    if (! function_exists('acf_add_local_field_group')) {
        return;
    }

    acf_add_local_field_group(array(
        'key' => 'group_textosemaforo_001',
        'title' => 'textosemaforo',
        'fields' => array(
            array(
                'key' => 'field_textosemaforo_titulo',
                'label' => 'titulo_textosemaforo',
                'name' => 'titulo_textosemaforo',
                'aria-label' => '',
                'type' => 'text',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '',
                    'class' => '',
                    'id' => '',
                ),
                'default_value' => '',
                'maxlength' => '',
                'placeholder' => '',
                'prepend' => '',
                'append' => '',
            ),
            array(
                'key' => 'field_textosemaforo_parrafo',
                'label' => 'parrafo_textosemaforo',
                'name' => 'parrafo_textosemaforo',
                'aria-label' => '',
                'type' => 'wysiwyg',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '',
                    'class' => '',
                    'id' => '',
                ),
                'default_value' => '',
                'placeholder' => '',
                'maxlength' => '',
                'rows' => 4,
                'new_lines' => 'wpautop',
            ),
            array(
                'key' => 'field_textosemaforo_repetidor',
                'label' => 'repetidor_semaforo',
                'name' => 'repetidor_semaforo',
                'aria-label' => '',
                'type' => 'repeater',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '',
                    'class' => '',
                    'id' => '',
                ),
                'layout' => 'table',
                'pagination' => 0,
                'min' => 0,
                'max' => 0,
                'collapsed' => '',
                'button_label' => 'Agregar Paso',
                'rows_per_page' => 20,
                'sub_fields' => array(
                    array(
                        'key' => 'field_textosemaforo_titulo_paso',
                        'label' => 'titulo_paso',
                        'name' => 'titulo_paso',
                        'aria-label' => '',
                        'type' => 'text',
                        'instructions' => '',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'default_value' => '',
                        'maxlength' => '',
                        'placeholder' => '',
                        'prepend' => '',
                        'append' => '',
                        'parent_repeater' => 'field_textosemaforo_repetidor',
                    ),
                    array(
                        'key' => 'field_textosemaforo_subtitulo_paso',
                        'label' => 'subtitulo_paso',
                        'name' => 'subtitulo_paso',
                        'aria-label' => '',
                        'type' => 'text',
                        'instructions' => '',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'default_value' => '',
                        'maxlength' => '',
                        'placeholder' => '',
                        'prepend' => '',
                        'append' => '',
                        'parent_repeater' => 'field_textosemaforo_repetidor',
                    )
                ),
            ),
            array(
                'key' => 'field_textosemaforo_imagen',
                'label' => 'imagen_textosemaforo',
                'name' => 'imagen_textosemaforo',
                'aria-label' => '',
                'type' => 'image',
                'instructions' => 'Imagen para desktop (mayor a 800px)',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '',
                    'class' => '',
                    'id' => '',
                ),
                'return_format' => 'array',
                'library' => 'all',
                'preview_size' => 'medium',
            ),
            array(
                'key' => 'field_textosemaforo_imagen_mobile',
                'label' => 'imagen_mobile_textosemaforo',
                'name' => 'imagen_mobile_textosemaforo',
                'aria-label' => '',
                'type' => 'image',
                'instructions' => 'Imagen para mobile (800px o menos)',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '',
                    'class' => '',
                    'id' => '',
                ),
                'return_format' => 'array',
                'library' => 'all',
                'preview_size' => 'medium',
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'block',
                    'operator' => '==',
                    'value' => 'acf/textosemaforo',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));
});

function textosemaforo_acf()
{
    acf_register_block_type([
        'name'        => 'textosemaforo',
        'title'        => __('textosemaforo', 'tictac'),
        'description'    => __('Bloque con título, párrafo, semáforo de pasos e imagen responsive', 'tictac'),
        'render_callback'  => 'textosemaforo',
        'mode'        => 'preview',
        'icon'        => 'lightbulb',
        'keywords'      => ['custom', 'textosemaforo', 'semaforo', 'pasos'],
    ]);
}

add_action('acf/init', 'textosemaforo_acf');

function textosemaforo_scripts()
{
    if (!is_admin()) {
        wp_enqueue_style('textosemaforo', get_stylesheet_directory_uri() . '/assets/functions/blocks/textosemaforo/textosemaforo.min.css');
        wp_enqueue_script('textosemaforo-js', get_stylesheet_directory_uri() . '/assets/functions/blocks/textosemaforo/textosemaforo.js', array(), '1.0.0', true);
        
        // Pasar las URLs de las imágenes al JavaScript
        $upload_dir = wp_upload_dir();
        wp_localize_script('textosemaforo-js', 'textoSemaforoData', array(
            'imagenInactiva' => $upload_dir['baseurl'] . '/2025/07/5fffe74ddf3130e7bf4894e67417eb025220228b.gif',
            'imagenActiva' => $upload_dir['baseurl'] . '/2025/07/9a924490e1407d3fa231c192e30945080aee47cb.gif'
        ));
    }
}
add_action('wp_enqueue_scripts', 'textosemaforo_scripts');

function textosemaforo($block)
{
    static $block_id = 0;
    $block_id++;
    
    $titulo = get_field("titulo_textosemaforo");
    $parrafo = get_field("parrafo_textosemaforo");
    $pasos = get_field("repetidor_semaforo");
    $imagen = get_field("imagen_textosemaforo");
    $imagen_mobile = get_field("imagen_mobile_textosemaforo");
    $upload_dir = wp_upload_dir();
?>
    <div class="container textosemaforo" data-semaforo-id="<?php echo $block_id; ?>">
        <div class="textosemaforo-content">
            <?php if ($titulo): ?>
                <h2 class="textosemaforo-titulo"><?= $titulo ?></h2>
            <?php endif; ?>
            
            <?php if ($parrafo): ?>
                <div class="textosemaforo-parrafo"><?= wpautop($parrafo) ?></div>
            <?php endif; ?>
        </div>

        <div class="textosemaforo-main-content">
            <?php if ($pasos) : ?>
                <div class="textosemaforo-semaforo">
                    <div class="semaforo-contenedor">
                        <?php 
                        $contador = 1;
                        foreach ($pasos as $paso) : 
                        ?>
                            <div class="semaforo-item">
                                <div class="semaforo-textos">
                                    <?php if ($paso['titulo_paso']) : ?>
                                        <p class="semaforo-titulo-paso"><?php echo $paso['titulo_paso']; ?></p>
                                    <?php endif; ?>
                                    <div class="divrayas">
                                        <img class="semaforo-imagen-gif" style="width: 70px;" src="<?php echo $upload_dir['baseurl']; ?>/2025/07/5fffe74ddf3130e7bf4894e67417eb025220228b.gif" alt="">
                                        <?php if ($paso['subtitulo_paso']) : ?>
                                        <p class="semaforo-subtitulo-paso"><?= $paso['subtitulo_paso']; ?></p>
                                    <?php endif; ?>
                                    </div>
                                    
                                </div>
                            </div>
                        <?php 
                        $contador++;
                        endforeach; 
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($imagen || $imagen_mobile): ?>
                <div class="textosemaforo-imagen">
                    <?php if ($imagen): ?>
                        <img class="imagen-desktop" 
                             src="<?php echo esc_url($imagen['url']); ?>" 
                             alt="<?php echo esc_attr($imagen['alt']); ?>">
                    <?php endif; ?>
                    
                    <?php if ($imagen_mobile): ?>
                        <img class="imagen-mobile" 
                             src="<?php echo esc_url($imagen_mobile['url']); ?>" 
                             alt="<?php echo esc_attr($imagen_mobile['alt']); ?>">
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
}