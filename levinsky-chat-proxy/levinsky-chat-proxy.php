<?php
/**
 * Plugin Name: Levinsky Chat Proxy
 * Description: WP REST endpoint that forwards chat messages to local Python bot.
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
  register_rest_route('levinsky/v1', '/chat', [
    'methods'  => 'POST',
    'callback' => 'levinsky_chat_handler',
    'permission_callback' => '__return_true',
  ]);
});

function levinsky_chat_handler(WP_REST_Request $request) {
  $body = $request->get_json_params();
  if (!$body || empty($body['message'])) {
    return new WP_REST_Response(['error' => 'missing message'], 400);
  }

  $python_url = 'http://127.0.0.1:8000/chat';

  $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $xff = $request->get_header('x-forwarded-for');
  if (!empty($xff)) {
    $client_ip = $xff;
  }

  $user_agent = $request->get_header('user-agent');
  if (empty($user_agent)) {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
  }

  $resp = wp_remote_post($python_url, [
    'headers' => [
      'Content-Type' => 'application/json',
      'X-Forwarded-For' => $client_ip,
      'User-Agent' => $user_agent,
    ],
    'timeout' => 25,
    'body'    => wp_json_encode([
      'message' => $body['message'],
    ]),
  ]);

  if (is_wp_error($resp)) {
    return new WP_REST_Response(['error' => $resp->get_error_message()], 500);
  }

  $code = wp_remote_retrieve_response_code($resp);
  $raw  = wp_remote_retrieve_body($resp);
  $json = json_decode($raw, true);

  if ($code !== 200 || !is_array($json)) {
    return new WP_REST_Response(['error' => 'python service error', 'details' => $raw], 500);
  }

  return new WP_REST_Response($json, 200);
}
