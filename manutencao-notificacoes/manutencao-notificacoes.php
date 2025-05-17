<?php

/**
 * Plugin Name: Notificações de Manutenção
 * Description: Envia notificações para clientes após atualizações no site via webhook ou e-mail.
 * Version: 1.2
 * Author: www.feitosa.digital
 * Author URI:  https://github.com/rafael-feitosa/
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Requires at least: 5.0  
 * Tested up to: 6.5
 */

if (!defined('ABSPATH')) exit;

// Adiciona submenu em Configurações
add_action('admin_menu', function () {
  add_options_page(
    'Notificações de Manutenção',
    'Notificações de manutenção',
    'manage_options',
    'notificacoes-manutencao',
    'nm_render_settings_page'
  );
});

// Renderiza a página de configurações
function nm_render_settings_page()
{
?>
  <div class="wrap">
    <h1>Notificações de Manutenção</h1>
    <hr>
    <form method="post" action="options.php">
      <?php
      settings_fields('nm_settings_group');
      do_settings_sections('notificacoes-manutencao');
      submit_button('Salvar configurações');
      ?>
    </form>

    <hr>

    <h2>Disparar notificação manual</h2>
    <form method="post">
      <?php wp_nonce_field('nm_disparar_notificacao', 'nm_nonce'); ?>
      <input type="submit" name="nm_disparar" class="button button-primary" value="Disparar notificação">
    </form>
  </div>
<?php
}

// Registra opções no banco de dados
add_action('admin_init', function () {
  register_setting('nm_settings_group', 'nm_webhook_url', [
    'sanitize_callback' => 'esc_url_raw'
  ]);

  register_setting('nm_settings_group', 'nm_email_cliente', [
    'sanitize_callback' => 'sanitize_email'
  ]);

  register_setting('nm_settings_group', 'nm_ativar_email', [
    'sanitize_callback' => function ($value) {
      return $value ? 1 : 0;
    }
  ]);

  register_setting('nm_settings_group', 'nm_email_subject', [
    'sanitize_callback' => 'sanitize_text_field'
  ]);

  register_setting('nm_settings_group', 'nm_email_template', [
    'sanitize_callback' => 'wp_kses_post'
  ]);

  add_settings_section(
    'nm_settings_section',
    'Configurações da Notificação',
    null,
    'notificacoes-manutencao'
  );

  add_settings_field(
    'nm_ativar_email',
    'Ativar envio via e-mail',
    function () {
      $checked = get_option('nm_ativar_email') ? 'checked' : '';
      echo '<label><input type="checkbox" name="nm_ativar_email" value="1" ' . esc_attr($checked) . '> Enviar e-mail diretamente sem automação externa</label>';
    },
    'notificacoes-manutencao',
    'nm_settings_section'
  );

  add_settings_field(
    'nm_email_cliente',
    'E-mail do cliente',
    function () {
      $value = esc_attr(get_option('nm_email_cliente'));
      echo '<input type="email" name="nm_email_cliente" value="' . esc_attr($value) . '" class="regular-text">';
    },
    'notificacoes-manutencao',
    'nm_settings_section'
  );

  add_settings_field(
    'nm_webhook_url',
    'URL do Webhook (Make)',
    function () {
      $value = esc_url(get_option('nm_webhook_url'));
      echo '<input type="url" name="nm_webhook_url" value="' . esc_attr($value) . '" class="regular-text">';
    },
    'notificacoes-manutencao',
    'nm_settings_section'
  );

  add_settings_field(
    'nm_email_subject',
    'Assunto do e-mail',
    function () {
      $value = esc_attr(get_option('nm_email_subject'));
      echo '<input type="text" name="nm_email_subject" value="' . esc_attr($value) . '" class="regular-text">';
    },
    'notificacoes-manutencao',
    'nm_settings_section'
  );

  add_settings_field(
    'nm_email_template',
    'Template do e-mail (HTML)',
    function () {
      $value = esc_textarea(get_option('nm_email_template'));
      echo '<textarea name="nm_email_template" rows="10" class="large-text code">' . esc_attr($value) . '</textarea>';
    },
    'notificacoes-manutencao',
    'nm_settings_section'
  );
});

// Disparo manual da notificação
add_action('admin_init', function () {
  if (!isset($_POST['nm_disparar']) || !isset($_POST['nm_nonce'])) return;
  $nonce = isset($_POST['nm_nonce']) ? sanitize_text_field(wp_unslash($_POST['nm_nonce'])) : '';
  if (!wp_verify_nonce($nonce, 'nm_disparar_notificacao')) return;

  $site_nome = get_bloginfo('name');
  $site_url = home_url();
  $data = current_time('Y-m-d H:i:s');
  $email_cliente = sanitize_email(get_option('nm_email_cliente'));
  $usar_email = get_option('nm_ativar_email');
  $assunto = sanitize_text_field(get_option('nm_email_subject'));
  $mensagem = get_option('nm_email_template');

  if ($usar_email && !empty($email_cliente) && !empty($mensagem)) {
    // Enviar via e-mail
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $enviado = wp_mail($email_cliente, $assunto, $mensagem, $headers);

    if ($enviado) {
      add_action('admin_notices', function () {
        echo '<div class="notice notice-success"><p>E-mail enviado com sucesso!</p></div>';
      });
    } else {
      add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Erro ao enviar o e-mail.</p></div>';
      });
    }
  } else {
    // Enviar via webhook
    $webhook = esc_url(get_option('nm_webhook_url'));
    if (empty($webhook)) {
      add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>URL do Webhook não configurada.</p></div>';
      });
      return;
    }

    $payload = [
      'site_nome' => $site_nome,
      'site_url' => $site_url,
      'data_envio' => $data,
      'email_cliente' => $email_cliente,
    ];

    $response = wp_remote_post($webhook, [
      'method' => 'POST',
      'headers' => ['Content-Type' => 'application/json'],
      'body' => json_encode($payload),
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
      $error_code = $response->get_error_code();
      $error_msg = $response->get_error_message();
      add_action('admin_notices', function () use ($error_code, $error_msg) {
        if ($error_code === 'http_request_failed' && str_contains($error_msg, 'cURL error 28')) {
          echo '<div class="notice notice-error"><p><strong>Erro:</strong> timeout ao conectar ao webhook. Verifique a URL.</p></div>';
        } else {
          echo '<div class="notice notice-error"><p><strong>Erro:</strong> ' . esc_html($error_msg) . '</p></div>';
        }
      });
    } else {
      $status = wp_remote_retrieve_response_code($response);
      if ($status >= 200 && $status < 300) {
        add_action('admin_notices', function () {
          echo '<div class="notice notice-success"><p>Notificação enviada com sucesso via Webhook!</p></div>';
        });
      } else {
        add_action('admin_notices', function () use ($status) {
          echo '<div class="notice notice-error"><p><strong>Erro:</strong> resposta HTTP inesperada (' . esc_html($status) . ').</p></div>';
        });
      }
    }
  }
});
