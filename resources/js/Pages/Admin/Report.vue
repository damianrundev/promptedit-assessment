<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    from: String,
    to: String,
    days: Array,
    totals: Object,
    reconciliation: Array,
    reconciliationError: String,
});

const range = reactive({ from: props.from, to: props.to });
const apply = () => router.get(route('admin.report'), { ...range }, { preserveState: true });
const usd = (v) => `$${v}`;
const activeDays = () => props.days.filter((d) => d.generations > 0);
</script>

<template>
    <Head title="Admin report" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-xl font-semibold leading-tight text-gray-800">Admin report</h2></template>

        <div class="space-y-8 py-8">
            <div class="mx-auto max-w-6xl space-y-8 px-4 sm:px-6 lg:px-8">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
                    <label class="text-sm text-gray-600">From (UTC)<input v-model="range.from" type="date" class="mt-1 block rounded-md border-gray-300" /></label>
                    <label class="text-sm text-gray-600">To (UTC)<input v-model="range.to" type="date" class="mt-1 block rounded-md border-gray-300" /></label>
                    <PrimaryButton>Apply</PrimaryButton>
                </form>

                <section class="grid gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Billed generations</p><p class="text-2xl font-bold tabular-nums">{{ totals.generations }}</p></div>
                    <div class="rounded-lg bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Charged to users</p><p class="text-2xl font-bold tabular-nums">{{ usd(totals.charged) }}</p></div>
                    <div class="rounded-lg bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Pixelforge cost</p><p class="text-2xl font-bold tabular-nums">{{ usd(totals.cost) }}</p></div>
                    <div class="rounded-lg bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Profit</p><p class="text-2xl font-bold tabular-nums">{{ usd(totals.profit) }}</p></div>
                </section>

                <section class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="font-semibold text-gray-900">Reconciliation with Pixelforge <code class="text-xs text-gray-500">GET /v1/usage</code></h3>
                    <p class="mt-1 text-xs text-gray-500">Fetched live. Pixelforge attributes each generation to the UTC day it <em>completed</em>. A day is green when cost, generation count and token totals all match exactly.</p>
                    <p v-if="reconciliationError" class="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-800">Could not reconcile: {{ reconciliationError }}</p>
                    <table v-else class="mt-3 w-full text-sm" data-test="reconciliation">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">Day (UTC)</th><th class="text-right">Our cost</th><th class="text-right">Pixelforge cost</th><th class="text-right">Gens (ours / theirs)</th><th class="text-right">Match</th></tr></thead>
                        <tbody>
                            <tr v-for="r in reconciliation" :key="r.date" :class="r.ok ? 'bg-green-50' : 'bg-red-50'" :data-ok="r.ok">
                                <td class="py-1 pl-2">{{ r.date }}</td>
                                <td class="text-right tabular-nums">{{ usd(r.ours) }}</td>
                                <td class="text-right tabular-nums">{{ usd(r.theirs) }}</td>
                                <td class="text-right tabular-nums">{{ r.our_generations }} / {{ r.their_generations }}</td>
                                <td class="pr-2 text-right font-semibold" :class="r.ok ? 'text-green-700' : 'text-red-700'">{{ r.ok ? '✓ matches' : '✗ mismatch' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="font-semibold text-gray-900">By day and user</h3>
                    <p v-if="!activeDays().length" class="mt-2 text-sm text-gray-500">No billed generations in this range.</p>
                    <table v-else class="mt-3 w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">Day / user</th><th class="text-right">Gens</th><th class="text-right">Charged</th><th class="text-right">Pixelforge cost</th><th class="text-right">Profit</th></tr></thead>
                        <tbody v-for="d in activeDays()" :key="d.date">
                            <tr class="border-t bg-gray-50 font-semibold">
                                <td class="py-1 pl-2">{{ d.date }}</td><td class="text-right tabular-nums">{{ d.generations }}</td>
                                <td class="text-right tabular-nums">{{ usd(d.charged) }}</td><td class="text-right tabular-nums">{{ usd(d.cost) }}</td><td class="pr-2 text-right tabular-nums">{{ usd(d.profit) }}</td>
                            </tr>
                            <tr v-for="u in d.users" :key="u.email" class="text-gray-700">
                                <td class="py-1 pl-6">{{ u.name }} <span class="text-xs text-gray-400">{{ u.email }}</span></td><td class="text-right tabular-nums">{{ u.generations }}</td>
                                <td class="text-right tabular-nums">{{ usd(u.charged) }}</td><td class="text-right tabular-nums">{{ usd(u.cost) }}</td><td class="pr-2 text-right tabular-nums">{{ usd(u.profit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
