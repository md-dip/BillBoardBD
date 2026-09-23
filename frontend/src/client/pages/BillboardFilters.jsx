const RADII = [5, 10, 20];

function normalizeSize(s) {
    const nums = String(s).match(/\d+(\.\d+)?/g);
    return nums ? nums.join('x') : String(s).toLowerCase().replace(/\s+/g, '');
}

function effectivePrice(b) {
    return b.pricing_mode === 'monthly' ? Number(b.monthly_rate) : Number(b.daily_rate);
}

// Applies the name/type/size/price filters this component controls to a billboard list.
export function filterBillboards(billboards, { nameQuery, typeFilter, sizeFilter, minPrice, maxPrice }) {
    return billboards.filter((b) => {
        if (nameQuery.trim() && !b.title.toLowerCase().includes(nameQuery.trim().toLowerCase())) return false;
        if (typeFilter && b.type.toLowerCase() !== typeFilter.toLowerCase()) return false;
        if (sizeFilter.trim() && !normalizeSize(b.size).includes(normalizeSize(sizeFilter))) return false;
        const price = effectivePrice(b);
        if (minPrice !== '' && price < Number(minPrice)) return false;
        if (maxPrice !== '' && price > Number(maxPrice)) return false;
        return true;
    });
}

export default function BillboardFilters({
    nameQuery, onNameQueryChange,
    radius, onRadiusClick,
    typeFilter, onTypeFilterChange, types,
    sizeFilter, onSizeFilterChange,
    minPrice, onMinPriceChange,
    maxPrice, onMaxPriceChange,
}) {
    return (
        <>
            <div className="filter-search-row">
                <input
                    className="filter-search"
                    type="search"
                    placeholder="Search by billboard name…"
                    value={nameQuery}
                    onChange={(e) => onNameQueryChange(e.target.value)}
                />
            </div>

            <div className="radius-pills">
                {RADII.map((r) => (
                    <button
                        key={r}
                        className={`pill ${radius === r ? 'active' : ''}`}
                        onClick={() => onRadiusClick(r)}
                    >
                        {r} km
                    </button>
                ))}
                <button
                    className={`pill ${radius === null ? 'active' : ''}`}
                    onClick={() => onRadiusClick(null)}
                >
                    All boards
                </button>
            </div>

            <div className="filter-row">
                <select
                    className="filter-select"
                    value={typeFilter}
                    onChange={(e) => onTypeFilterChange(e.target.value)}
                >
                    <option value="">All types</option>
                    {types.map((t) => (
                        <option key={t} value={t}>{t.toUpperCase()}</option>
                    ))}
                </select>
                <input
                    className="filter-input"
                    placeholder="Size e.g. 20×10"
                    value={sizeFilter}
                    onChange={(e) => onSizeFilterChange(e.target.value)}
                />
            </div>

            <div className="filter-row">
                <input
                    className="filter-input"
                    placeholder="Min ৳"
                    value={minPrice}
                    onChange={(e) => onMinPriceChange(e.target.value)}
                />
                <input
                    className="filter-input"
                    placeholder="Max ৳"
                    value={maxPrice}
                    onChange={(e) => onMaxPriceChange(e.target.value)}
                />
            </div>
        </>
    );
}
