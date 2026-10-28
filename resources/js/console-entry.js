// A VPS's console (docs/proxmox.md, B6): each connection asks the page's ticket (a POST with the CSRF token), then
// noVNC opens the relay's websocket with it; the VNC password is the ticket Proxmox gave.
import RFB from '@novnc/novnc';

const root = document.getElementById('console');
const screen = root.querySelector('[data-screen]');
const status = root.querySelector('[data-status]');
const strings = JSON.parse(root.dataset.strings);
let rfb = null;

function say(text) {
    status.textContent = text;
}

async function connect() {
    if (rfb) {
        rfb.disconnect();
        rfb = null;
    }
    say(strings.connecting);
    let ticket;
    try {
        const response = await fetch(root.dataset.ticket, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        });
        ticket = await response.json();
        if (!response.ok) {
            say(ticket.message || strings.failed);
            return;
        }
    } catch {
        say(strings.failed);
        return;
    }
    rfb = new RFB(screen, ticket.url, { credentials: { password: ticket.password }, wsProtocols: ['binary'] });
    rfb.scaleViewport = true;
    rfb.addEventListener('connect', () => say(strings.connected));
    rfb.addEventListener('disconnect', () => say(strings.disconnected));
    rfb.addEventListener('credentialsrequired', () => rfb.sendCredentials({ password: ticket.password }));
}

root.querySelector('[data-action="ctrlaltdel"]').addEventListener('click', () => rfb && rfb.sendCtrlAltDel());
root.querySelector('[data-action="reconnect"]').addEventListener('click', connect);
root.querySelector('[data-action="fullscreen"]').addEventListener('click', () => document.documentElement.requestFullscreen?.());

connect();
