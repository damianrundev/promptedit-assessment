<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({ sizes: Array, qualities: Array, markup: String });

const MAX_PROMPT = 4000;
const form = useForm({ prompt: '', size: '1024x1024', quality: 'medium', reference_images: [] });
const estimate = ref(null);
const estimateError = ref(null);
const loading = ref(false);

const qualityHint = { low: 'Fast, simple images', medium: 'Balanced (default)', high: 'Most detail, costs the most' };

let timer = null;
let seq = 0;
const refresh = () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const mine = ++seq;
        loading.value = true;
        try {
            const { data } = await window.axios.post(route('generations.estimate'), {
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: form.reference_images.filter((u) => u.trim() !== ''),
            });
            if (mine === seq) {
                estimate.value = data;
                estimateError.value = null;
            }
        } catch (e) {
            if (mine === seq) estimateError.value = 'Could not load the estimate right now.';
        } finally {
            if (mine === seq) loading.value = false;
        }
    }, 250);
};
watch(() => [form.prompt, form.size, form.quality, form.reference_images.length], refresh, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

const addImage = () => form.reference_images.length < 4 && form.reference_images.push('');
const removeImage = (i) => form.reference_images.splice(i, 1);

const canSubmit = computed(
    () => !form.processing && form.prompt.trim().length > 0 && form.prompt.length <= MAX_PROMPT && estimate.value && estimate.value.can_afford,
);

const submit = () => {
    form.transform((d) => ({ ...d, reference_images: d.reference_images.filter((u) => u.trim() !== '') })).post(route('generations.store'));
};

const tokenRows = computed(() => {
    if (!estimate.value) return [];
    const { min, max } = estimate.value.tokens;
    return [
        ['Prompt text', min.input_text_tokens, max.input_text_tokens, 'about 1 token per 4 characters, ±15%'],
        ['Reference images', min.input_image_tokens, max.input_image_tokens, '765 per image, exact'],
        ['Image output', min.output_tokens, max.output_tokens, 'exact for this size and quality'],
        ['Reasoning', min.reasoning_tokens, max.reasoning_tokens, 'the model thinks before drawing; varies'],
    ];
});
</script>

<template>
    <Head title="Generate" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-xl font-semibold leading-tight text-gray-800">Generate an image</h2></template>

        <div class="py-8">
            <div class="mx-auto grid max-w-6xl gap-6 px-4 sm:px-6 lg:grid-cols-3 lg:px-8">
                <form class="space-y-5 rounded-lg bg-white p-6 shadow-sm lg:col-span-2" @submit.prevent="submit">
                    <div>
                        <label for="prompt" class="block text-sm font-medium text-gray-700">Prompt</label>
                        <textarea id="prompt" v-model="form.prompt" rows="5" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="A lighthouse on a cliff at dawn, oil painting" />
                        <div class="mt-1 flex justify-between text-xs" :class="form.prompt.length > MAX_PROMPT ? 'text-red-600' : 'text-gray-500'">
                            <InputError :message="form.errors.prompt" />
                            <span class="ml-auto">{{ form.prompt.length }} / {{ MAX_PROMPT }}</span>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Size</label>
                            <select v-model="form.size" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                <option v-for="s in props.sizes" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <InputError :message="form.errors.size" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Quality</label>
                            <select v-model="form.quality" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                <option v-for="q in props.qualities" :key="q" :value="q">{{ q }}: {{ qualityHint[q] }}</option>
                            </select>
                            <InputError :message="form.errors.quality" />
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label class="block text-sm font-medium text-gray-700">Reference images (optional, up to 4)</label>
                            <button type="button" class="text-sm text-indigo-600 hover:underline disabled:text-gray-400" :disabled="form.reference_images.length >= 4" @click="addImage">+ Add URL</button>
                        </div>
                        <p class="text-xs text-gray-500">Each reference image adds 765 input tokens to the price.</p>
                        <div v-for="(url, i) in form.reference_images" :key="i" class="mt-2">
                            <div class="flex gap-2">
                                <input v-model="form.reference_images[i]" type="url" placeholder="https://example.com/style.png" class="w-full rounded-md border-gray-300 shadow-sm" />
                                <button type="button" class="rounded-md border border-gray-300 px-3 text-gray-600 hover:bg-gray-50" @click="removeImage(i)" aria-label="Remove image">✕</button>
                            </div>
                            <InputError :message="form.errors[`reference_images.${i}`]" />
                        </div>
                        <InputError :message="form.errors.reference_images" />
                    </div>

                    <div v-if="form.errors.credits" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ form.errors.credits }}</div>

                    <div class="flex items-center gap-3">
                        <PrimaryButton :disabled="!canSubmit">Generate</PrimaryButton>
                        <span v-if="estimate && !estimate.can_afford" class="text-sm text-red-600">
                            Not enough credits for the maximum ({{ estimate.max }}). Use “Buy credits” above.
                        </span>
                    </div>
                </form>

                <aside class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-900">Estimated price</h3>
                    <div v-if="estimate" :class="{ 'opacity-60': loading }" data-test="estimate">
                        <p class="text-3xl font-bold tracking-tight text-gray-900">{{ estimate.min }} <span class="text-gray-400">–</span> {{ estimate.max }}</p>
                        <p class="mt-1 text-sm text-gray-500">Your balance: {{ estimate.balance }}</p>
                    </div>
                    <p v-else-if="estimateError" class="text-sm text-red-600">{{ estimateError }}</p>
                    <p v-else class="text-sm text-gray-500">Calculating…</p>

                    <div class="rounded-md bg-indigo-50 p-3 text-xs leading-relaxed text-indigo-900">
                        <p class="font-semibold">Why a range?</p>
                        <p class="mt-1">
                            You pay for tokens. Image output and reference images are exact, but two parts are only known when the
                            generation finishes: the number of reasoning tokens (the model “thinks” a variable amount) and the exact text
                            tokens of your prompt (±15%). You are charged the real price, which always falls inside this range, plus a
                            {{ props.markup }} service fee (already included above).
                        </p>
                        <p class="mt-1">The maximum is held from your balance while it runs, and whatever is not used is released. Failed generations cost nothing.</p>
                    </div>

                    <table v-if="estimate" class="w-full text-xs text-gray-600">
                        <thead><tr class="text-left text-gray-400"><th class="font-medium">Tokens</th><th class="text-right font-medium">Min</th><th class="text-right font-medium">Max</th></tr></thead>
                        <tbody>
                            <tr v-for="r in tokenRows" :key="r[0]" :title="r[3]">
                                <td>{{ r[0] }}</td><td class="text-right tabular-nums">{{ r[1].toLocaleString() }}</td><td class="text-right tabular-nums">{{ r[2].toLocaleString() }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <Link :href="route('generations.index')" class="block text-sm text-indigo-600 hover:underline">View history →</Link>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
