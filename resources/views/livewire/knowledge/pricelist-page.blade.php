<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold">Pricelist PDFs</h1>
            <p class="mt-1 text-sm text-gray-500">Folder: <span class="font-mono">{{ $directory }}</span></p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="text-sm font-semibold mb-3">Upload Pricelist</h2>
        <form method="POST" action="{{ route('pricelists.store') }}" enctype="multipart/form-data" class="flex flex-col gap-3 md:flex-row md:items-center">
            @csrf
            <input name="pricelist_file" type="file" accept=".pdf" class="text-sm" required>
            <button type="submit"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 md:w-auto">
                Upload PDF
            </button>
        </form>
        @error('pricelist_file')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="bg-white rounded-xl border border-gray-200">
        <div class="px-5 py-3 border-b border-gray-100">
            <h2 class="text-sm font-semibold">Uploaded Files</h2>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse($files as $file)
                <div class="flex items-center justify-between px-5 py-4">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ $file['filename'] }}</p>
                        <p class="text-xs text-gray-500">
                            {{ number_format($file['size'] / 1024, 1) }} KB ·
                            {{ \Carbon\Carbon::createFromTimestamp($file['last_modified'])->format('d M Y H:i') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('pricelists.download', $file['filename']) }}"
                           class="text-sm text-indigo-600 hover:underline">
                            Download
                        </a>
                        <form method="POST" action="{{ route('pricelists.destroy', $file['filename']) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="text-sm text-red-500 hover:text-red-700"
                                    onclick="return confirm('Delete this pricelist PDF?')">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="px-5 py-10 text-center text-sm text-gray-400">
                    Belum ada file pricelist PDF.
                </div>
            @endforelse
        </div>
    </div>
</div>
