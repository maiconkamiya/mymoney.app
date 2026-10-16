import type {
    AgentActivity,
    AgentActivityAgent,
    AgentActivityMessage,
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
    approved: 'aprova',
    rejected: 'rejeita',
    neutral: 'neutro',
    veto: 'veta',
};

const MONO = '"Geist Mono Variable", "Geist Mono", ui-monospace, monospace';
const TEAL = '#35d6c5';
const PINK = '#ff4d8d';

const MESSAGE_GAP_MS = 520;
const MESSAGE_FLIGHT_MS = 1300;
const MESSAGE_LINGER_MS = 2600;
const TRIP_MS = 4200;

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

/**
 * Draws the agents at work: data nebulae, the agents travelling to read them,
 * and the messages they pass on the table. It holds no data of its own; it
 * paints whatever AgentActivity it is given.
 */
export class AgentCanvas {
    private ctx: CanvasRenderingContext2D;
    private width = 0;
    private height = 0;
    private dpr = 1;
    private activity: AgentActivity | null = null;
    private playStart = 0;
    private replay = false;
    private raf = 0;
    private running = false;
    private reduced: boolean;
    private sources: Source[] = [];
    private nebulae: HTMLCanvasElement | null = null;
    private colors = { up: '#2fbf8f', down: '#ef5a53', warn: '#f5b84b' };

    constructor(private canvas: HTMLCanvasElement) {
        this.ctx = canvas.getContext('2d') as CanvasRenderingContext2D;
        this.reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        this.readColors();
    }

    setActivity(activity: AgentActivity): void {
        const changed =
            this.activity?.signal?.version !== activity.signal?.version;
        this.activity = activity;

        if (changed && activity.status !== 'empty') {
            this.playStart = performance.now();
            this.replay = true;
        }

        if (this.reduced) {
            this.frame(performance.now());
        }
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

        if (this.reduced || !this.running) {
            this.frame(performance.now());
        }
    }

    start(): void {
        if (this.running || this.reduced) {
            return;
        }

        this.running = true;
        const loop = (now: number) => {
            if (!this.running) {
                return;
            }

            this.frame(now);
            this.raf = requestAnimationFrame(loop);
        };
        this.raf = requestAnimationFrame(loop);
    }

    stop(): void {
        this.running = false;
        cancelAnimationFrame(this.raf);
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
            return { x: cx, y: this.height * 0.9 };
        }

        const index = Math.max(VOTER_ORDER.indexOf(role), 0);
        const angle = ((210 + index * 60) * Math.PI) / 180;

        return {
            x: cx + Math.cos(angle) * this.width * 0.23,
            y: cy + Math.sin(angle) * this.height * 0.3,
        };
    }

    private source(role: string): Source | null {
        const key = SOURCE_OF[role];

        return this.sources.find((s) => s.key === key) ?? null;
    }

    private stateColor(state: string): string {
        switch (state) {
            case 'approved':
                return this.colors.up;
            case 'rejected':
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

    /** Position of an agent: reading trips happen while it analyzes or while the cycle replays. */
    private position(
        agent: AgentActivityAgent,
        now: number,
        index: number,
    ): {
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
        let phase: number | null = null;

        if (agent.state === 'analyzing' && !this.reduced) {
            phase = (((now / TRIP_MS + index * 0.17) % 1) + 1) % 1;
        } else if (this.replay && !this.reduced) {
            const elapsed = (now - this.playStart) / TRIP_MS - index * 0.06;
            phase = elapsed >= 0 && elapsed < 1 ? elapsed : null;
        }

        if (phase === null) {
            return { at: from, away: 0, from, to, control };
        }

        const away =
            phase < 0.35
                ? ease(phase / 0.35)
                : phase < 0.65
                  ? 1
                  : 1 - ease((phase - 0.65) / 0.35);

        return { at: quad(from, control, to, away), away, from, to, control };
    }

    private frame(now: number): void {
        const { ctx, width, height } = this;

        if (!width || !height || !this.activity) {
            return;
        }

        if (this.replay && now - this.playStart > this.replayLength()) {
            this.replay = false;
        }

        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        ctx.fillStyle = '#07080c';
        ctx.fillRect(0, 0, width, height);

        if (this.nebulae) {
            ctx.drawImage(this.nebulae, 0, 0, width, height);
        }

        this.drawSourceLabels(now);

        const agents = this.activity.agents;
        const live = this.activity.status === 'live';
        const positions = new Map(
            agents.map((agent, i) => [
                agent.role,
                this.position(agent, now, i),
            ]),
        );

        this.drawLinks(agents, positions, now);
        this.drawTrails(positions);

        agents.forEach((agent) => {
            const pos = positions.get(agent.role)!;
            this.drawAgent(agent, pos.at, now, live);
        });

        this.drawPackets(positions, now);
    }

    private replayLength(): number {
        const count = this.activity?.messages.length ?? 0;

        return (
            TRIP_MS +
            count * MESSAGE_GAP_MS +
            MESSAGE_FLIGHT_MS +
            MESSAGE_LINGER_MS
        );
    }

    private drawSourceLabels(now: number): void {
        const { ctx } = this;
        const reading = new Set<string>();

        this.activity?.agents.forEach((agent, i) => {
            const pos = this.position(agent, now, i);

            if (pos.away > 0.9) {
                const key = SOURCE_OF[agent.role];

                if (key) {
                    reading.add(key);
                }
            }
        });

        ctx.textBaseline = 'alphabetic';
        this.sources.forEach((src) => {
            const x = src.at.x * this.width;
            const y =
                src.at.y * this.height -
                Math.min(this.width, this.height) * 0.19;
            const align = src.at.x < 0.5 ? 'left' : 'right';
            const anchorX =
                src.at.x < 0.5
                    ? Math.max(x - 60, 10)
                    : Math.min(x + 60, this.width - 10);

            ctx.textAlign = align;
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
            if (agent.role === 'coordinator') {
                return;
            }

            const to = center;
            const pos = positions.get(agent.role)!.at;
            const active = agent.state !== 'idle';

            if (agent.role === 'executor' && agent.state === 'idle') {
                return;
            }

            ctx.strokeStyle = this.stateColor(agent.state);
            ctx.globalAlpha = active ? 0.45 : 0.12;
            ctx.setLineDash(agent.role === 'executor' ? [] : [2, 5]);
            ctx.lineDashOffset = this.reduced ? 0 : -now / 60;
            ctx.beginPath();
            ctx.moveTo(pos.x, pos.y);
            ctx.lineTo(to.x, to.y);
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

    private drawAgent(
        agent: AgentActivityAgent,
        at: Point,
        now: number,
        live: boolean,
    ): void {
        const { ctx } = this;
        const big = agent.role === 'coordinator';
        const radius = big ? 17 : 12;
        const color = this.stateColor(agent.state);
        const t = this.reduced ? 0 : now / 1000;
        const busy = agent.state === 'analyzing' || agent.state === 'waiting';
        const spikes = big ? 36 : 26;

        ctx.save();
        ctx.translate(at.x, at.y);
        ctx.lineWidth = 1;
        ctx.strokeStyle = TEAL;
        ctx.fillStyle = TEAL;

        ctx.globalAlpha = agent.state === 'idle' && !live ? 0.55 : 0.9;
        for (let i = 0; i < spikes; i++) {
            const angle = (i / spikes) * Math.PI * 2;
            const breath =
                1 +
                (busy ? 0.45 : 0.15) * Math.sin(t * (busy ? 3 : 1.2) + i * 0.9);
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
        const core = radius * (0.38 + (busy ? 0.08 * Math.sin(t * 5) : 0));
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

    /** Message tags flying between the agents, then lingering where they landed. */
    private drawPackets(
        positions: Map<string, ReturnType<AgentCanvas['position']>>,
        now: number,
    ): void {
        const messages = this.activity?.messages ?? [];

        if (this.reduced || !this.replay) {
            this.drawResting(messages);

            return;
        }

        const base = now - this.playStart - TRIP_MS * 0.55;

        messages.forEach((message, i) => {
            const age = base - i * MESSAGE_GAP_MS;

            if (age < 0 || age > MESSAGE_FLIGHT_MS + MESSAGE_LINGER_MS) {
                return;
            }

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

    private drawResting(messages: AgentActivityMessage[]): void {
        const center = this.home('coordinator');
        const shown = messages.slice(-4);

        shown.forEach((message, i) => {
            this.drawTag(
                message,
                center.x + (i % 2 === 0 ? -1 : 1) * 70,
                center.y - 30 + Math.floor(i / 2) * 26,
                0.9,
                true,
            );
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
                  46,
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
