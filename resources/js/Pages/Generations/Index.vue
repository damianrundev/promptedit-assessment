<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ generations: Array });

const badge = {
    queued: 'bg-gray-100 text-gray-700',
    processing: 'bg-blue-100 text-blue-800',
    succeeded: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
};

// Poll while anything is still moving (or a finished image is still being copied to our storage).
const polling = computed(() => props.generations.some((g) => g.active || (g.status === 'succeeded' && !g.has_image)));

let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (polling.value && !document.hidden) router.reload({ only: ['generations', 'auth'], preserveScroll: true });
    }, 2500);
});
onBeforeUnmount(() => clearInterval(timer));

const when = (iso) => new Date(iso).toLocaleString();
</script>

<template>
    <Head title="History" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">History</h2>
                <span v-if="polling" class="flex items-center gap-2 text-xs text-gray-500"><span class="h-2 w-2 animate-pulse rounded-full bg-blue-500"></span> Updating live</span>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div v-if="!generations.length" class="rounded-lg bg-white p-10 text-center text-gray-500 shadow-sm">
                    No generations yet. <Link :href="route('generations.create')" class="text-indigo-600 hover:underline">Create your first one</Link>.
                </div>

                <article v-for="g in generations" :key="g.id" class="flex gap-4 rounded-lg bg-white p-4 shadow-sm" :data-status="g.status">
                    <div class="flex h-32 w-32 shrink-0 items-center justify-center overflow-hidden rounded-md bg-gray-100 text-xs text-gray-400">
                        <img v-if="g.has_image" :src="route('generations.image', g.id)" :alt="g.prompt" class="h-full w-full object-cover" />
                        <span v-else-if="g.status === 'succeeded'">Saving image…</span>
                        <span v-else-if="g.status === 'failed'">No image</span>
                        <span v-else class="animate-pulse">{{ g.status === 'processing' ? 'Drawing…' : 'Waiting…' }}</span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="badge[g.status]">{{ g.status }}</span>
                            <span class="text-xs text-gray-500">{{ g.size }} · {{ g.quality }} · {{ g.reference_images.length }} ref image{{ g.reference_images.length === 1 ? '' : 's' }}</span>
                            <span class="ml-auto text-xs text-gray-400">{{ when(g.created_at) }}</span>
                        </div>
                        <p class="mt-2 line-clamp-2 text-sm text-gray-900">{{ g.prompt }}</p>

                        <p v-if="g.note" class="mt-2 text-xs text-amber-700">{{ g.note }}</p>

                        <div v-if="g.error" class="mt-2 rounded-md border border-red-200 bg-red-50 p-2 text-sm text-red-800">
                            <span class="font-medium">Failed:</span> {{ g.error.message }}
                            <span v-if="g.error.code" class="ml-1 text-xs text-red-500">({{ g.error.code }})</span>
                        </div>

                        <dl class="mt-3 flex gap-8 text-sm">
                            <div>
                                <dt class="text-xs text-gray-500">Estimated</dt>
                                <dd class="tabular-nums text-gray-700">{{ g.estimate }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Actual cost</dt>
                                <dd class="font-semibold tabular-nums text-gray-900">{{ g.cost ?? 'pending' }}</dd>
                            </div>
                        </dl>
                    </div>
                </article>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
