export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type TokenUsage = {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
};

export type PricingCatalog = {
    rates: {
        input_text_tokens: string;
        input_image_tokens: string;
        output_tokens: string;
        reasoning_tokens: string;
    };
    characters_per_text_token: number;
    text_token_variance_bps: number;
    per_reference_image: number;
    output: Record<string, Record<string, number>>;
    reasoning: Record<string, { min: number; max: number }>;
};

export type BreakdownLine = {
    type: keyof TokenUsage;
    label: string;
    tokens: number;
    rate_usd_per_million: string;
    cost_nanos: string;
};

export type Generation = {
    id: string;
    status: GenerationStatus;
    is_terminal: boolean;
    waiting_reason: string | null;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimate: { min_nanos: string; expected_nanos: string; max_nanos: string };
    hold_nanos: string;
    charged_nanos: string | null;
    breakdown: {
        lines: BreakdownLine[];
        provider_cost_nanos: string;
        markup_percent: number;
        markup_nanos: string;
        charged_nanos: string;
    } | null;
    image_url: string | null;
    image_expired: boolean;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
};

export type CreditTransaction = {
    id: number;
    type: 'bonus' | 'purchase' | 'hold' | 'hold_release' | 'charge';
    label: string;
    amount_nanos: string;
    balance_after_nanos: string;
    generation_id: string | null;
    generation_prompt: string | null;
    created_at: string;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
        links: PaginationLink[];
    };
};
