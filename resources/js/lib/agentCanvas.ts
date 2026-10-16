import type {
    AgentActivity,
    AgentActivityAgent,
    AgentActivityMessage,
    AgentLiveEvent,
} from '@/types';

type Point = { x: number; y: number };

type Source = {
    key: string;
    label: string;
    at: Point;
    hue: number;
    points: Point[];
    links: [number, number][];
};

const SOURCES: Omit<Source, 'points' | 'links'>[] = [
    { key: 'quotes', label: 'cotações', at: { x: 0.14, y: 0.24 }, hue: 265 },
    { key: 'news', label: 'notícias', at: { x: 0.86, y: 0.22 }, hue: 32 },
    {
        key: 'fundamentals',
        label: 'fundamentos',
        at: { x: 0.13, y: 0.78 },
        hue: 285,
    },
    { key: 'quant', label: 'modelo', at: { x: 0.87, y: 0.78 }, hue: 168 },
];

/** Where each agent goes to read before it votes. */
const SOURCE_OF: Record<string, string> = {
    technical: 'quotes',
    risk: 'quotes',
    news: 'news',
    fundamentals: 'fundamentals',
    quant: 'quant',
};

const VOTER_ORDER = [
    'technical',
    'news',
    'fundamentals',
    'quant',
    'risk',
    'skeptic',
];

/** Each voter sits on the ring next to the source it reads (the skeptic, with no source, on the right). */
const VOTER_ANGLE: Record<string, number> = {
    technical: 240,
    news: 300,
    fundamentals: 120,
    quant: 60,
    risk: 180,
    skeptic: 0,
};

const SHORT_LABEL: Record<string, string> = {
    coordinator: 'coordenador',
    executor: 'executor',
    technical: 'técnico',
    news: 'notícias',
    fundamentals: 'fundamentos',
    quant: 'quant',
    skeptic: 'cético',
    risk: 'risco',
};

const STATE_TEXT: Record<string, string> = {
    idle: 'ocioso',
    analyzing: 'analisando',
    waiting: 'aguardando',
    buy: 'compra',
    sell: 'venda',
    approved: 'aprova',
    rejected: 'rejeita',
    neutral: 'neutro',
    veto: 'veta',
};

const MONO = '"Geist Mono Variable", "Geist Mono", ui-monospace, monospace';
const TEAL = '#35d6c5';
const PINK = '#ff4d8d';

const MESSAGE_FLIGHT_MS = 1300;
const MESSAGE_LINGER_MS = 2600;
const TRAVEL_PER_SECOND = 1.4;

function rng(seed: number): () => number {
    let a = seed;

    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function ease(t: number): number {
    return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
}

function lerp(a: number, b: number, t: number): number {
    return a + (b - a) * t;
}

function quad(a: Point, c: Point, b: Point, t: number): Point {
    const u = 1 - t;

    return {
        x: u * u * a.x + 2 * u * t * c.x + t * t * b.x,
        y: u * u * a.y + 2 * u * t * c.y + t * t * b.y,
    };
}

type Packet = { message: AgentActivityMessage; start: number };

/**
 * Draws the agents at work: data nebulae, the agents that go to read them and
 * the messages they pass on the table. Nothing moves on its own: an agent
 * leaves for its source when its start is announced, comes back when its vote
 * arrives, and a message flies when someone really sent it. Between events
 * the picture is still and the loop sleeps.
 */
export class AgentCanvas {
    private ctx: CanvasRenderingContext2D;
    private width = 0;
    private height = 0;
    private dpr = 1;
    private activity: AgentActivity | null = null;
    private raf = 0;
    private looping = false;
    private lastFrame = 0;
    private reduced: boolean;
    private sources: Source[] = [];
    private nebulae: HTMLCanvasElement | null = null;
    private colors = { up: '#2fbf8f', down: '#ef5a53', warn: '#f5b84b' };
    /** How far each agent is on its way to its source (0 home, 1 reading), and where it is heading. */
    private away = new Map<string, { value: number; target: 0 | 1 }>();
    private packets: Packet[] = [];
    private cycleActive = false;

    constructor(private canvas: HTMLCanvasElement) {
        this.ctx = canvas.getContext('2d') as CanvasRenderingContext2D;
        this.reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        this.readColors();
    }

    /** The picture as it is now: states and the last messages, with nobody travelling. */
    setActivity(activity: AgentActivity): void {
        this.activity = activity;

        if (!this.cycleActive) {
            this.away.clear();
        }

        this.wake();
    }

    /** One real thing an agent did; `activity` already includes it. */
    applyEvent(event: AgentLiveEvent, activity: AgentActivity): void {
        this.activity = activity;
        const now = performance.now();

        switch (event.type) {
            case 'cycle.started':
                this.cycleActive = true;
                this.away.clear();
                this.packets = [];
                break;
            case 'agent.started':
                if (SOURCE_OF[event.role]) {
                    this.away.set(event.role, {
                        value: this.away.get(event.role)?.value ?? 0,
                        target: 1,
                    });
                }

                break;
            case 'agent.voted': {
                const entry = this.away.get(event.role);

                if (entry) {
                    entry.target = 0;
                }

                const sent = activity.messages
                    .filter((m) => m.from === event.role)
                    .slice(-2);
                sent.forEach((message) =>
                    this.packets.push({ message, start: now }),
                );
                break;
            }
            case 'order.started':
                this.packets.push({
                    message: {
                        from: 'coordinator',
                        to: 'executor',
                        kind: 'decision',
                        state: 'approved',
                        round: null,
                        text: 'Enviando a ordem.',
                    },
                    start: now,
                });
                break;
            case 'consensus.decided': {
                this.cycleActive = false;
                const decision = activity.messages.find(
                    (m) => m.kind === 'decision',
                );

                if (decision) {
                    this.packets.push({ message: decision, start: now });
                }

                break;
            }
            case 'order.finished':
                this.cycleActive = false;
                break;
        }

        this.wake();
    }

    resize(): void {
        const rect = this.canvas.getBoundingClientRect();

        if (rect.width === 0 || rect.height === 0) {
            return;
        }

        this.dpr = Math.min(window.devicePixelRatio || 1, 2);
        this.width = rect.width;
        this.height = rect.height;
        this.canvas.width = Math.round(rect.width * this.dpr);
        this.canvas.height = Math.round(rect.height * this.dpr);
        this.readColors();
        this.buildNebulae();
        this.frame(performance.now(), 0);
    }

    stop(): void {
        this.looping = false;
        cancelAnimationFrame(this.raf);
    }

    /** Starts the loop if something is still moving; it stops by itself when everything settles. */
    private wake(): void {
        if (this.reduced) {
            this.settle();
            this.frame(performance.now(), 0);

            return;
        }

        if (this.looping) {
            return;
        }

        this.looping = true;
        this.lastFrame = performance.now();
        const loop = (now: number) => {
            if (!this.looping) {
                return;
            }

            const dt = Math.min((now - this.lastFrame) / 1000, 0.1);
            this.lastFrame = now;
            this.frame(now, dt);

            if (this.moving(now)) {
                this.raf = requestAnimationFrame(loop);
            } else {
                this.looping = false;
            }
        };
        this.raf = requestAnimationFrame(loop);
    }

    /** With reduced motion nobody travels: the states alone tell the story. */
    private settle(): void {
        this.away.clear();
        this.packets = [];
    }

    private moving(now: number): boolean {
        const travelling = [...this.away.values()].some(
            (a) => a.value !== a.target,
        );
        const busy =
            this.activity?.agents.some((a) => a.state === 'analyzing') ?? false;

        return (
            travelling ||
            (busy && this.cycleActive) ||
            this.packets.some(
                (p) => now - p.start < MESSAGE_FLIGHT_MS + MESSAGE_LINGER_MS,
            )
        );
    }

    private readColors(): void {
        const style = getComputedStyle(this.canvas);
        const pick = (name: string, fallback: string) =>
            style.getPropertyValue(name).trim() || fallback;

        this.colors = {
            up: pick('--up', this.colors.up),
            down: pick('--down', this.colors.down),
            warn: this.colors.warn,
        };
    }

    private buildNebulae(): void {
        const offscreen = document.createElement('canvas');
        offscreen.width = this.canvas.width;
        offscreen.height = this.canvas.height;
        const g = offscreen.getContext('2d') as CanvasRenderingContext2D;
        g.scale(this.dpr, this.dpr);

        const reach = Math.min(this.width, this.height) * 0.2;

        this.sources = SOURCES.map((base, index) => {
            const random = rng(1000 + index * 97);
            const cx = base.at.x * this.width;
            const cy = base.at.y * this.height;
            const points: Point[] = [];

            for (let i = 0; i < 64; i++) {
                const angle = random() * Math.PI * 2;
                const radius =
                    reach * Math.sqrt(random()) * (0.6 + random() * 0.5);
                points.push({
                    x: cx + Math.cos(angle) * radius * 1.25,
                    y: cy + Math.sin(angle) * radius * 0.9,
                });
            }

            const links: [number, number][] = [];

            points.forEach((p, i) => {
                const near = points
                    .map((q, j) => ({ j, d: Math.hypot(p.x - q.x, p.y - q.y) }))
                    .filter((n) => n.j !== i)
                    .sort((a, b) => a.d - b.d)
                    .slice(0, 2);
                near.forEach((n) => links.push([i, n.j]));
            });

            const glow = g.createRadialGradient(cx, cy, 0, cx, cy, reach * 1.6);
            glow.addColorStop(0, `hsla(${base.hue}, 70%, 55%, 0.16)`);
            glow.addColorStop(1, `hsla(${base.hue}, 70%, 55%, 0)`);
            g.fillStyle = glow;
            g.fillRect(cx - reach * 2, cy - reach * 2, reach * 4, reach * 4);

            g.strokeStyle = `hsla(${base.hue}, 60%, 70%, 0.16)`;
            g.lineWidth = 0.6;
            g.beginPath();
            links.forEach(([a, b]) => {
                g.moveTo(points[a].x, points[a].y);
                g.lineTo(points[b].x, points[b].y);
            });
            g.stroke();

            g.fillStyle = `hsla(${base.hue}, 80%, 82%, 0.6)`;
            points.forEach((p, i) => {
                g.beginPath();
                g.arc(p.x, p.y, i % 9 === 0 ? 1.8 : 1, 0, Math.PI * 2);
                g.fill();
            });

            return { ...base, points, links };
        });

        this.nebulae = offscreen;
    }

    private home(role: string): Point {
        const cx = this.width / 2;
        const cy = this.height * 0.5;

        if (role === 'coordinator') {
            return { x: cx, y: cy };
        }

        if (role === 'executor') {
            // Fixed distance from the bottom so its label is never cut off.
            return { x: cx, y: this.height - 62 };
        }

        const angle = ((VOTER_ANGLE[role] ?? 0) * Math.PI) / 180;
        const reach = this.width < 600 ? 0.3 : 0.23;

        return {
            x: cx + Math.cos(angle) * this.width * reach,
            y: cy + Math.sin(angle) * this.height * 0.26,
        };
    }

    private source(role: string): Source | null {
        const key = SOURCE_OF[role];

        return this.sources.find((s) => s.key === key) ?? null;
    }

    private stateColor(state: string): string {
        switch (state) {
            case 'approved':
            case 'buy':
                return this.colors.up;
            case 'rejected':
            case 'sell':
                return this.colors.down;
            case 'veto':
                return this.colors.warn;
            case 'analyzing':
            case 'waiting':
                return PINK;
            case 'challenge':
                return PINK;
            default:
                return '#6b7384';
        }
    }

    /** Agents move only along their own away value, which only events change. */
    private position(agent: AgentActivityAgent): {
        at: Point;
        away: number;
        from: Point;
        to: Point | null;
        control: Point;
    } {
        const from = this.home(agent.role);
        const src = this.source(agent.role);

        if (!src) {
            return { at: from, away: 0, from, to: null, control: from };
        }

        const to = {
            x: lerp(src.at.x * this.width, from.x, 0.12),
            y: lerp(src.at.y * this.height, from.y, 0.12),
        };
        const control = {
            x: (from.x + to.x) / 2,
            y: Math.min(from.y, to.y) - this.height * 0.12,
        };
        const away = ease(this.away.get(agent.role)?.value ?? 0);

        return { at: quad(from, control, to, away), away, from, to, control };
    }

    private step(dt: number): void {
        this.away.forEach((entry) => {
            const delta = TRAVEL_PER_SECOND * dt;
            entry.value =
                entry.target === 1
                    ? Math.min(entry.value + delta, 1)
                    : Math.max(entry.value - delta, 0);
        });
    }

    private frame(now: number, dt: number): void {
        const { ctx, width, height } = this;

        if (!width || !height || !this.activity) {
            return;
        }

        this.step(dt);

        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        ctx.fillStyle = '#07080c';
        ctx.fillRect(0, 0, width, height);

        if (this.nebulae) {
            ctx.drawImage(this.nebulae, 0, 0, width, height);
        }

        const agents = this.activity.agents;
        const positions = new Map(
            agents.map((agent) => [agent.role, this.position(agent)]),
        );

        this.drawSourceLabels(positions);
        this.drawLinks(agents, positions, now);
        this.drawTrails(positions);

        agents.forEach((agent) => {
            this.drawAgent(agent, positions.get(agent.role)!.at, now);
        });

        this.drawTags(positions, now);
    }

    private drawSourceLabels(
        positions: Map<string, ReturnType<AgentCanvas['position']>>,
    ): void {
        const { ctx } = this;
        const reading = new Set<string>();

        positions.forEach((pos, role) => {
            if (pos.away > 0.9 && SOURCE_OF[role]) {
                reading.add(SOURCE_OF[role]);
            }
        });

        ctx.textBaseline = 'alphabetic';
        this.sources.forEach((src) => {
            const x = src.at.x * this.width;
            const y =
                src.at.y * this.height -
                Math.min(this.width, this.height) * 0.19;
            const anchorX =
                src.at.x < 0.5
                    ? Math.max(x - 60, 10)
                    : Math.min(x + 60, this.width - 10);

            ctx.textAlign = src.at.x < 0.5 ? 'left' : 'right';
            ctx.font = `600 11px ${MONO}`;
            ctx.fillStyle = `hsl(${src.hue}, 80%, 85%)`;
            ctx.fillText(src.label, anchorX, Math.max(y, 18));
            ctx.font = `10px ${MONO}`;
            ctx.fillStyle = 'rgba(255,255,255,0.4)';
            ctx.fillText(
                reading.has(src.key) ? 'lendo' : 'em espera',
                anchorX,
                Math.max(y, 18) + 13,
            );
        });
    }

    private drawLinks(
        agents: AgentActivityAgent[],
        positions: Map<string, ReturnType<AgentCanvas['position']>>,
        now: number,
    ): void {
        const { ctx } = this;
        const center = positions.get('coordinator')!.at;

        ctx.lineWidth = 1;
        agents.forEach((agent) => {
            if (
                agent.role === 'coordinator' ||
                (agent.role === 'executor' && agent.state === 'idle')
            ) {
                return;
            }

            const pos = positions.get(agent.role)!.at;
            const active = agent.state !== 'idle';

            ctx.strokeStyle = this.stateColor(agent.state);
            ctx.globalAlpha = active ? 0.45 : 0.12;
            ctx.setLineDash(agent.role === 'executor' ? [] : [2, 5]);
            ctx.lineDashOffset = agent.state === 'analyzing' ? -now / 60 : 0;
            ctx.beginPath();
            ctx.moveTo(pos.x, pos.y);
            ctx.lineTo(center.x, center.y);
            ctx.stroke();
        });
        ctx.setLineDash([]);
        ctx.globalAlpha = 1;
    }

    /** The thin white arc left behind by an agent that went to read. */
    private drawTrails(
        positions: Map<string, ReturnType<AgentCanvas['position']>>,
    ): void {
        const { ctx } = this;

        positions.forEach((pos) => {
            if (pos.away <= 0.02 || !pos.to) {
                return;
            }

            ctx.strokeStyle = `rgba(255,255,255,${0.55 * pos.away})`;
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(pos.from.x, pos.from.y);

            for (let i = 1; i <= 24; i++) {
                const p = quad(
                    pos.from,
                    pos.control,
                    pos.to,
                    (i / 24) * pos.away,
                );
                ctx.lineTo(p.x, p.y);
            }

            ctx.stroke();
        });
    }

    private drawAgent(agent: AgentActivityAgent, at: Point, now: number): void {
        const { ctx } = this;
        const big = agent.role === 'coordinator';
        const radius = big ? 17 : 12;
        const color = this.stateColor(agent.state);
        const busy = agent.state === 'analyzing' || agent.state === 'waiting';
        // Only an agent doing something pulses; a quiet one is still.
        const t = busy && this.cycleActive && !this.reduced ? now / 1000 : 0;
        const spikes = big ? 36 : 26;

        ctx.save();
        ctx.translate(at.x, at.y);
        ctx.lineWidth = 1;
        ctx.strokeStyle = TEAL;
        ctx.fillStyle = TEAL;

        ctx.globalAlpha = agent.state === 'idle' ? 0.55 : 0.9;
        for (let i = 0; i < spikes; i++) {
            const angle = (i / spikes) * Math.PI * 2;
            const breath = 1 + (t > 0 ? 0.45 * Math.sin(t * 3 + i * 0.9) : 0);
            const inner = radius * 1.05;
            const outer = radius * (1.55 + (i % 3) * 0.18) * breath;
            ctx.beginPath();
            ctx.moveTo(Math.cos(angle) * inner, Math.sin(angle) * inner);
            ctx.lineTo(Math.cos(angle) * outer, Math.sin(angle) * outer);
            ctx.stroke();
            ctx.beginPath();
            ctx.arc(
                Math.cos(angle) * outer,
                Math.sin(angle) * outer,
                0.9,
                0,
                Math.PI * 2,
            );
            ctx.fill();
        }

        ctx.globalAlpha = 1;
        ctx.fillStyle = 'rgba(53,214,197,0.14)';
        ctx.beginPath();
        ctx.arc(0, 0, radius, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = color;
        ctx.shadowColor = color;
        ctx.shadowBlur = busy ? 12 : 6;
        const core = radius * (0.38 + (t > 0 ? 0.08 * Math.sin(t * 5) : 0));
        ctx.beginPath();
        ctx.arc(0, 0, core, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();

        const below = at.y + radius * 2.1 + 10;
        const label = SHORT_LABEL[agent.role] ?? agent.role;
        let status = STATE_TEXT[agent.state] ?? agent.state;

        if (agent.confidence !== null && agent.state !== 'analyzing') {
            status += ` ${Math.round(agent.confidence * 100)}%`;
        }

        ctx.textAlign = 'center';
        ctx.font = `600 11px ${MONO}`;
        ctx.fillStyle = 'rgba(255,255,255,0.92)';
        ctx.fillText(label, at.x, below);
        ctx.font = `10px ${MONO}`;
        ctx.fillStyle = color;
        ctx.fillText(status, at.x, below + 12);
    }

    private target(role: string): Point[] {
        if (role === 'voters') {
            return VOTER_ORDER.filter((r) => r !== 'skeptic').map((r) =>
                this.home(r),
            );
        }

        return [this.home(role)];
    }

    /** Messages that really flew, lingering where they landed; at rest, the last ones of the cycle. */
    private drawTags(
        positions: Map<string, ReturnType<AgentCanvas['position']>>,
        now: number,
    ): void {
        const live = this.packets.filter(
            (p) => now - p.start < MESSAGE_FLIGHT_MS + MESSAGE_LINGER_MS,
        );

        this.packets = live;

        if (live.length === 0) {
            if (!this.cycleActive) {
                this.drawResting(this.activity?.messages ?? []);
            }

            return;
        }

        live.forEach((packet, i) => {
            const age = now - packet.start;
            const { message } = packet;
            const from =
                positions.get(message.from)?.at ?? this.home(message.from);
            const targets = this.target(message.to);

            targets.forEach((to, k) => {
                const flight = Math.min(age / MESSAGE_FLIGHT_MS, 1);
                const control = {
                    x: (from.x + to.x) / 2 + (k - targets.length / 2) * 8,
                    y: (from.y + to.y) / 2 - this.height * 0.06,
                };
                const p = quad(from, control, to, ease(flight));
                const fade =
                    age > MESSAGE_FLIGHT_MS
                        ? 1 - (age - MESSAGE_FLIGHT_MS) / MESSAGE_LINGER_MS
                        : 1;
                const lift =
                    age > MESSAGE_FLIGHT_MS
                        ? -((age - MESSAGE_FLIGHT_MS) / 80)
                        : 0;

                this.drawTag(
                    message,
                    p.x,
                    p.y + lift - 14 - (i % 3) * 4,
                    fade,
                    k === 0,
                );
            });
        });
    }

    /** The last messages of the cycle, one per line in the free band at the top, clear of every agent and label. */
    private drawResting(messages: AgentActivityMessage[]): void {
        const shown = messages.slice(this.width < 600 ? -3 : -4);

        shown.forEach((message, i) => {
            this.drawTag(message, this.width / 2, 44 + i * 19, 0.92, true);
        });
    }

    private drawTag(
        message: AgentActivityMessage,
        x: number,
        y: number,
        alpha: number,
        withText: boolean,
    ): void {
        const { ctx } = this;
        const fillColor = this.stateColor(message.state);
        const solid =
            message.kind === 'challenge' ||
            message.state === 'approved' ||
            message.state === 'rejected' ||
            message.state === 'veto';
        const text = withText
            ? `${SHORT_LABEL[message.from] ?? message.from}: ${message.text}`.slice(
                  0,
                  this.width < 600 ? 30 : 46,
              )
            : '';

        ctx.save();
        ctx.globalAlpha = Math.max(alpha, 0);
        ctx.font = `10px ${MONO}`;
        ctx.textAlign = 'left';

        if (!withText) {
            ctx.fillStyle = fillColor;
            ctx.beginPath();
            ctx.arc(x, y + 14, 2.5, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();

            return;
        }

        const w = ctx.measureText(text).width + 12;
        const left = Math.min(Math.max(x - w / 2, 6), this.width - w - 6);

        if (solid) {
            ctx.fillStyle = fillColor;
            ctx.fillRect(left, y - 9, w, 16);
            ctx.fillStyle = '#07080c';
        } else {
            ctx.strokeStyle = 'rgba(255,255,255,0.35)';
            ctx.strokeRect(left + 0.5, y - 8.5, w - 1, 15);
            ctx.fillStyle = 'rgba(255,255,255,0.8)';
        }

        ctx.fillText(text, left + 6, y + 3);
        ctx.restore();
    }
}
