/**
 * Browser-local signing for the post meta box.
 *
 * WordPress supplies the authoritative, filtered payload. This file creates
 * an Ed25519 key in Web Crypto, stores the non-extractable private key in
 * IndexedDB, and sends only the public key plus signature bytes back to PHP.
 */
(function($) {
    'use strict';

    const config = content_signing_post_meta_box;
    const databaseName = 'htmltrust-local-signing';
    const storeName = 'keys';

    function toCanonicalBase64(bytes) {
        let binary = '';
        bytes.forEach(function(byte) { binary += String.fromCharCode(byte); });
        return btoa(binary).replace(/=+$/g, '');
    }

    function openKeyStore() {
        return new Promise(function(resolve, reject) {
            if (!window.indexedDB) {
                reject(new Error('This browser does not provide IndexedDB.'));
                return;
            }
            const request = indexedDB.open(databaseName, 1);
            request.onupgradeneeded = function() {
                request.result.createObjectStore(storeName);
            };
            request.onsuccess = function() { resolve(request.result); };
            request.onerror = function() { reject(request.error || new Error('Could not open local key storage.')); };
        });
    }

    function loadStoredKey(authorId, rotate) {
        return openKeyStore().then(function(db) {
            return new Promise(function(resolve, reject) {
                const transaction = db.transaction(storeName, 'readonly');
                const store = transaction.objectStore(storeName);
                const storageKey = 'author:' + authorId;
                const request = store.get(storageKey);
                request.onsuccess = function() {
                    if (request.result && !rotate) {
                        resolve(request.result);
                        return;
                    }
                    crypto.subtle.generateKey({name: 'Ed25519'}, false, ['sign', 'verify']).then(function(keyPair) {
                        // The private key is non-extractable from generation.
                        // Web Crypto keeps the public member exportable so it
                        // can be published as a resolver document.
                        return keyPair;
                    }).then(function(keyPair) {
                        const idPart = crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2);
                        const keyId = config.key_base_url + authorId + '.' + idPart;
                        const value = {keyId: keyId, keyPair: keyPair};
                        const writeTransaction = db.transaction(storeName, 'readwrite');
                        writeTransaction.objectStore(storeName).put(value, storageKey);
                        writeTransaction.oncomplete = function() { resolve(value); };
                        writeTransaction.onerror = function() { reject(writeTransaction.error || new Error('Could not store local key.')); };
                    }).catch(reject);
                };
                request.onerror = function() { reject(request.error || new Error('Could not read local key.')); };
            });
        });
    }

    function exportPublicKey(key) {
        return crypto.subtle.exportKey('raw', key).then(function(buffer) {
            return toCanonicalBase64(new Uint8Array(buffer));
        });
    }

    function ajax(data) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: config.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        resolve(response.data && response.data.data ? response.data.data : response.data);
                    } else {
                        reject(new Error(response.data && response.data.message ? response.data.message : config.error_text));
                    }
                },
                error: function() { reject(new Error(config.ajax_error || config.error_text)); }
            });
        });
    }

    function signPost($button) {
        const postId = $button.data('post-id');
        const $spinner = $button.siblings('.spinner');
        $button.prop('disabled', true).text(config.signing_text);
        $spinner.addClass('is-active');

        loadStoredKey(config.author_id, false).then(function(stored) {
            return ajax({action: 'content_signing_prepare_local_signing', nonce: config.nonce, post_id: postId, keyid: stored.keyId}).then(function(prepared) {
                const payload = new TextEncoder().encode(prepared.payload);
                return crypto.subtle.sign({name: 'Ed25519'}, stored.keyPair.privateKey, payload).then(function(signature) {
                    return exportPublicKey(stored.keyPair.publicKey).then(function(publicKey) {
                        return ajax({
                            action: 'content_signing_complete_local_signing', nonce: config.nonce, post_id: postId,
                            prepareToken: prepared.prepareToken, keyid: stored.keyId, publicKey: publicKey, signature: toCanonicalBase64(new Uint8Array(signature)),
                            contentHash: prepared.contentHash, claimsHash: prepared.claimsHash, domain: prepared.domain,
                            signedAt: prepared.signedAt, payload: prepared.payload, profile: prepared.profile,
                            algorithm: prepared.algorithm, scope: prepared.scope, location: prepared.location,
                            sourceURL: prepared.sourceURL
                        });
                    });
                });
            });
        }).then(function() {
            window.location.reload();
        }).catch(function(error) {
            alert(config.local_signing_error + ' ' + error.message);
            $button.prop('disabled', false).text(config.sign_post_text);
            $spinner.removeClass('is-active');
        });
    }

    function init() {
        $('.content-signing-meta-box .sign-post').on('click', function(event) {
            event.preventDefault();
            if (confirm(config.sign_post_confirm)) { signPost($(this)); }
        });

        $('.content-signing-meta-box .rotate-local-key').on('click', function(event) {
            event.preventDefault();
            if (!confirm(config.rotate_confirm)) { return; }
            loadStoredKey($(this).data('author-id'), true).then(function() {
                alert('Local signing key rotated.');
            }).catch(function(error) { alert(config.local_signing_error + ' ' + error.message); });
        });

        $('.content-signing-meta-box .verify-signature').on('click', function(event) {
            event.preventDefault();
            const $button = $(this);
            const $listItem = $button.closest('li');
            $button.prop('disabled', true).text(config.verifying_text);
            ajax({action: 'content_signing_verify_signature', nonce: config.nonce, signature_id: $button.data('signature-id'), post_id: $button.data('post-id')}).then(function(result) {
                $listItem.append($('<div>').addClass('verify-result ' + (result.valid ? 'valid' : 'invalid')).text(result.valid ? config.valid_text : config.invalid_text));
            }).catch(function(error) {
                $listItem.append($('<div>').addClass('verify-result invalid').text(config.error_text + ' ' + error.message));
            }).finally(function() {
                $button.prop('disabled', false).text(config.verify_text);
            });
        });
    }

    $(document).ready(init);
}(jQuery));
