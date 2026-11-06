// A VPS's console (docs/proxmox.md, B6 and «Σχέδιο: το relay στο cluster»): each connection asks the page's ticket (a
// POST with the CSRF token), then opens the relay's websocket with it. The relay's first frame is the VNC password, as
// text; then the open websocket is noVNC's.
import RFB from '@novnc/novnc';

const root = document.getElementById('console');
const screen = root.querySelector('[data-screen]');
const status = root.querySelector('[data-status]');
const strings = JSON.parse(root.dataset.strings);
let rfb = null;
let generation = 0; // a reconnect's own: events and answers of an older one are ignored (review of #506)
let busy = false;

function say(text) {
    status.textContent = text;
}

async function connect() {
    if (busy) {
        return; // a click while a ticket is asked
    }
    busy = true;
    const mine = ++generation;
    if (rfb) {
        const old = rfb;
        rfb = null;
        old.disconnect();
    }
    say(strings.connecting);
    try {
        await open(mine);
    } finally {
        busy = false;
    }
}

async function open(mine) {
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
    if (mine !== generation) {
        return;
    }
    const socket = new WebSocket(ticket.url, ['binary']);
    socket.binaryType = 'arraybuffer';
    socket.onclose = () => mine === generation && say(strings.failed);
    socket.onmessage = (event) => {
        // The first frame only: the password, as text. Handed over at once, in this event, so no frame of the screen
        // arrives before noVNC listens.
        socket.onmessage = null;
        socket.onclose = null;
        if (mine !== generation || typeof event.data !== 'string') {
            socket.close();
            return;
        }
        attach(mine, socket, event.data);
    };
}

function attach(mine, socket, password) {
    const current = new RFB(screen, socket, { credentials: { password } });
    rfb = current;
    current.scaleViewport = true;
    const ifCurrent = (handler) => () => mine === generation && handler();
    current.addEventListener('connect', ifCurrent(() => say(strings.connected)));
    current.addEventListener('disconnect', ifCurrent(() => say(strings.disconnected)));
    current.addEventListener('credentialsrequired', ifCurrent(() => current.sendCredentials({ password })));
}

root.querySelector('[data-action="ctrlaltdel"]').addEventListener('click', () => rfb && rfb.sendCtrlAltDel());
root.querySelector('[data-action="reconnect"]').addEventListener('click', connect);
root.querySelector('[data-action="fullscreen"]').addEventListener('click', () => document.documentElement.requestFullscreen?.());

connect();
