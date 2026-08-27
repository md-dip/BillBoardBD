// One place to format money as Bangladeshi Taka, so every screen matches.
export function formatBDT(amount) {
    return `৳${Number(amount).toLocaleString('en-US')}`;
}