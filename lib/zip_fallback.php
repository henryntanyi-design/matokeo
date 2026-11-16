<?php
/**
 * ZipArchive fallback kwa seva ambayo extension ya "zip" haijawashwa.
 *
 * PhpSpreadsheet hutumia ZipArchive kusoma faili za .xlsx (ambazo ni ZIP).
 * Kuandika .xlsx hakuhitaji ZipArchive (hutumia ZipStream ya pure-PHP), lakini
 * kusoma template/upload kunahitaji. Darasa hili hutoa sehemu ya ZipArchive
 * inayotumika (open, statName, locateName, getFromName, getNameIndex, close,
 * addFromString, addFile) kwa kutumia zlib pekee.
 *
 * Halitangazwi kama extension ya zip imewashwa.
 */
if (!class_exists('ZipArchive')) {
    class ZipArchive
    {
        const CREATE = 1;
        const EXCL = 2;
        const CHECKCONS = 4;
        const OVERWRITE = 8;

        const FL_NOCASE = 1;
        const FL_NODIR = 2;
        const FL_COMPRESSED = 4;
        const FL_UNCHANGED = 8;

        const CM_DEFAULT = -1;
        const CM_STORE = 0;
        const CM_DEFLATE = 8;

        const ER_OK = 0;
        const ER_MULTIDISK = 1;
        const ER_RENAME = 2;
        const ER_CLOSE = 3;
        const ER_SEEK = 4;
        const ER_READ = 5;
        const ER_WRITE = 6;
        const ER_CRC = 7;
        const ER_ZIPCLOSED = 8;
        const ER_NOENT = 9;
        const ER_EXISTS = 10;
        const ER_OPEN = 11;
        const ER_TMPOPEN = 12;
        const ER_ZLIB = 13;
        const ER_MEMORY = 14;
        const ER_CHANGED = 15;
        const ER_COMPNOTSUPP = 16;
        const ER_EOF = 17;
        const ER_INVAL = 18;
        const ER_NOZIP = 19;
        const ER_INTERNAL = 20;
        const ER_INCONS = 21;
        const ER_REMOVE = 22;
        const ER_DELETED = 23;

        public $numFiles = 0;
        public $filename = '';

        /** @var array<int,array> Milango ya archive iliyosomwa */
        private $entries = [];
        /** @var string[] Majina kwa mpangilio */
        private $order = [];
        /** @var string|null Maudhui ya faili la ZIP */
        private $data = null;
        /** @var bool Archive inaandikwa */
        private $writable = false;
        /** @var bool Kuna mabadiliko yasiyoandikwa */
        private $dirty = false;
        /** @var array<string,string> Maudhui ya kuandika */
        private $writeEntries = [];
        /** @var string[] Mpangilio wa kuandika */
        private $writeOrder = [];

        public function open($filename, $flags = 0)
        {
            $this->reset();
            $this->filename = (string)$filename;
            $flags = (int)$flags;

            // OVERWRITE = archive mpya; maudhui ya zamani hayasomwi.
            if ($flags & self::OVERWRITE) {
                if (is_file($this->filename) || ($flags & self::CREATE)) {
                    $this->writable = true;
                    return true;
                }
                return self::ER_NOENT;
            }

            if (!is_file($this->filename)) {
                if ($flags & (self::CREATE | self::OVERWRITE)) {
                    $this->writable = true;
                    return true;
                }
                return self::ER_NOENT;
            }

            $data = @file_get_contents($this->filename);
            if ($data === false) {
                return self::ER_OPEN;
            }

            // Faili tupu + CREATE = archive mpya.
            if ($data === '' && ($flags & self::CREATE)) {
                $this->writable = true;
                return true;
            }
            if (strlen($data) < 22 || substr($data, 0, 2) !== "PK") {
                return self::ER_NOZIP;
            }

            $eocd = strrpos($data, "PK\x05\x06");
            if ($eocd === false || strlen($data) < $eocd + 22) {
                return self::ER_NOZIP;
            }

            $end = unpack('vdisk/vcddisk/ventries/vtotal/Vcdsize/Vcdoff/vcomment', substr($data, $eocd + 4, 18));
            $count = (int)$end['entries'];
            $offset = (int)$end['cdoff'];
            if ($count === 0xFFFF || $offset === 0xFFFFFFFF) {
                return self::ER_NOZIP; // ZIP64 haitumiki na .xlsx za kawaida
            }

            for ($i = 0; $i < $count; $i++) {
                if (substr($data, $offset, 4) !== "PK\x01\x02") {
                    break;
                }
                $c = unpack(
                    'vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voff',
                    substr($data, $offset + 4, 42)
                );
                $name = (string)substr($data, $offset + 46, (int)$c['nlen']);
                $offset += 46 + (int)$c['nlen'] + (int)$c['elen'] + (int)$c['clen'];
                if ($name === '' || substr($name, -1) === '/') {
                    continue;
                }
                $this->order[] = $name;
                $this->entries[] = [
                    'name' => $name,
                    'flags' => (int)$c['flags'],
                    'method' => (int)$c['method'],
                    'crc' => (int)$c['crc'],
                    'csize' => (int)$c['csize'],
                    'usize' => (int)$c['usize'],
                    'offset' => (int)$c['off'],
                ];
            }

            $this->data = $data;
            $this->numFiles = count($this->order);
            return true;
        }

        public function close()
        {
            if ($this->writable && $this->dirty) {
                @file_put_contents($this->filename, $this->buildArchive());
            }
            $this->reset();
            return true;
        }

        public function getFromName($name, $length = 0, $flags = 0)
        {
            $index = $this->locateName($name, $flags);
            if ($index === false) {
                return false;
            }
            $contents = $this->extract($this->entries[$index]);
            if ($contents === false) {
                return false;
            }
            return ((int)$length) > 0 ? substr($contents, 0, (int)$length) : $contents;
        }

        public function getFromIndex($index, $length = 0, $flags = 0)
        {
            $index = (int)$index;
            if (!isset($this->entries[$index])) {
                return false;
            }
            $contents = $this->extract($this->entries[$index]);
            if ($contents === false) {
                return false;
            }
            return ((int)$length) > 0 ? substr($contents, 0, (int)$length) : $contents;
        }

        public function locateName($name, $flags = 0)
        {
            $name = ltrim((string)$name, '/');
            $nocase = ((int)$flags & self::FL_NOCASE) !== 0;
            foreach ($this->order as $i => $entryName) {
                $same = $nocase
                    ? strcasecmp(ltrim($entryName, '/'), $name) === 0
                    : ltrim($entryName, '/') === $name;
                if ($same) {
                    return $i;
                }
            }
            return false;
        }

        public function statName($name, $flags = 0)
        {
            $index = $this->locateName($name, $flags);
            if ($index === false) {
                return false;
            }
            $e = $this->entries[$index];
            return [
                'name' => $e['name'],
                'index' => $index,
                'crc' => $e['crc'],
                'size' => $e['usize'],
                'mtime' => 0,
                'comp_size' => $e['csize'],
                'comp_method' => $e['method'],
            ];
        }

        public function getNameIndex($index, $flags = 0)
        {
            $index = (int)$index;
            return $this->order[$index] ?? false;
        }

        public function count()
        {
            return count($this->order);
        }

        public function addFromString($localname, $contents)
        {
            if ($this->filename === '') {
                return false;
            }
            $localname = ltrim((string)$localname, '/');
            if ($localname === '') {
                return false;
            }
            if (!array_key_exists($localname, $this->writeEntries)) {
                $this->writeOrder[] = $localname;
            }
            $this->writeEntries[$localname] = (string)$contents;
            $this->writable = true;
            $this->dirty = true;
            return true;
        }

        public function addFile($filename, $localname = null)
        {
            $contents = @file_get_contents($filename);
            if ($contents === false) {
                return false;
            }
            return $this->addFromString($localname === null ? $filename : $localname, $contents);
        }

        private function reset()
        {
            $this->entries = [];
            $this->order = [];
            $this->data = null;
            $this->numFiles = 0;
            $this->writable = false;
            $this->dirty = false;
            $this->writeEntries = [];
            $this->writeOrder = [];
        }

        private function extract(array $entry)
        {
            if (($entry['flags'] & 1) !== 0) {
                return false; // archive iliyofungwa kwa nenosiri
            }
            if ($entry['method'] === self::CM_STORE) {
                return $this->readLocalData($entry['offset'], $entry['csize']);
            }
            if ($entry['method'] !== self::CM_DEFLATE) {
                return false;
            }
            $compressed = $this->readLocalData($entry['offset'], $entry['csize']);
            if ($compressed === false || !function_exists('gzinflate')) {
                return false;
            }
            $out = @gzinflate($compressed);
            return $out === false ? false : $out;
        }

        private function readLocalData($offset, $size)
        {
            if ($this->data === null || substr($this->data, $offset, 4) !== "PK\x03\x04") {
                return false;
            }
            if (strlen($this->data) < $offset + 30) {
                return false;
            }
            $h = unpack('vnlen/velen', substr($this->data, $offset + 26, 4));
            $start = $offset + 30 + (int)$h['nlen'] + (int)$h['elen'];
            if (strlen($this->data) < $start + $size) {
                return false;
            }
            return (string)substr($this->data, $start, $size);
        }

        /** Tengeneza ZIP (method=store) kutoka maudhui ya kuandika. */
        private function buildArchive()
        {
            $local = '';
            $central = '';
            $offset = 0;
            $dosDate = 0x0021; // 1980-01-01
            $dosTime = 0;

            foreach ($this->writeOrder as $name) {
                $contents = $this->writeEntries[$name];
                $crc = crc32($contents);
                if ($crc < 0) {
                    $crc += 4294967296;
                }
                $size = strlen($contents);
                $nlen = strlen($name);

                $localHeader = "PK\x03\x04"
                    . pack('vvvvv', 20, 0, self::CM_STORE, $dosTime, $dosDate)
                    . pack('VVV', $crc, $size, $size)
                    . pack('vv', $nlen, 0)
                    . $name;
                $local .= $localHeader . $contents;

                $central .= "PK\x01\x02"
                    . pack('vvvvvv', 20, 20, 0, self::CM_STORE, $dosTime, $dosDate)
                    . pack('VVV', $crc, $size, $size)
                    . pack('vvvvv', $nlen, 0, 0, 0, 0)
                    . pack('V', 0) . pack('V', $offset)
                    . $name;

                $offset += strlen($localHeader) + $size;
            }

            $count = count($this->writeOrder);
            return $local . $central . "PK\x05\x06"
                . pack('vvvv', 0, 0, $count, $count)
                . pack('VV', strlen($central), $offset)
                . pack('v', 0);
        }
    }
}
