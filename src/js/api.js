/**
 * Wrapper reutilizable de peticiones Fetch (AJAX) para la SPA.
 * Envía las credenciales de sesión y redirige a login.html ante 401.
 */
window.API = (function () {
    async function request(method, url, data, options) {
        options = options || {};

        const fetchOptions = {
            method: method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }
        };

        if (data !== null && data !== undefined) {
            fetchOptions.body = JSON.stringify(data);
        }

        let response;
        try {
            response = await fetch(url, fetchOptions);
        } catch (error) {
            throw new Error('Error de conexión con el servidor.');
        }

        if (response.status === 401 && options.redirectOnUnauthorized !== false) {
            window.location.href = 'login.html';
            throw new Error('Sesión no válida o expirada.');
        }

        let body;
        try {
            body = await response.json();
        } catch (error) {
            throw new Error('Respuesta inválida del servidor.');
        }

        body._status = response.status;
        return body;
    }

    return {
        get: function (url, options) {
            return request('GET', url, null, options);
        },
        post: function (url, data, options) {
            return request('POST', url, data, options);
        },
        put: function (url, data, options) {
            return request('PUT', url, data, options);
        },
        delete: function (url, data, options) {
            return request('DELETE', url, data, options);
        }
    };
})();