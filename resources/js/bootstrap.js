import axios from 'axios';

/**
 * Configuración global de axios para la autenticación SPA de Sanctum.
 *
 * La sesión viaja en una cookie httpOnly, no en un token: el JavaScript de la página no puede
 * leerla, así que un XSS no puede robarla. A cambio hay que protegerse de CSRF, y de eso se
 * encarga el par de cookies XSRF-TOKEN + cabecera X-XSRF-TOKEN que axios maneja solo.
 */
axios.defaults.withCredentials = true;

// Desde axios 1.6 hay que pedir explícitamente que lea la cookie XSRF-TOKEN y la reenvíe
// como cabecera; sin esto, cualquier POST/PUT/PATCH contra /api responde 419.
axios.defaults.withXSRFToken = true;

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';

export default axios;
