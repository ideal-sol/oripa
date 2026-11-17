<?php

namespace Tests\Unit;

use App\Domain\Line\Services\V2LineMediaInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class V2LineMediaInspectorTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    {
        $this->directory=sys_get_temp_dir().'/d5-fixture-'.bin2hex(random_bytes(8)); mkdir($this->directory,0700);
    }
    protected function tearDown(): void { foreach(glob($this->directory.'/*') as $file) unlink($file); rmdir($this->directory); }
    private function png(int $width=320,int $height=180,string $name='input'): string
    {
        self::assertTrue(extension_loaded('gd'),'GD must exist in the test runtime');
        $image=imagecreatetruecolor($width,$height); $path=$this->directory.'/'.$name;
        imagepng($image,$path); imagedestroy($image); return $path;
    }
    public function test_verified_image_and_five_imagemap_widths(): void
    {
        $i=new V2LineMediaInspector; $path=$this->png(1040,520);
        self::assertSame('image/png',$i->image($path)['mime']);
        foreach([240,300,460,700,1040] as $width) {
            $result=$i->resize($path,$this->directory.'/'.$width,$width);
            self::assertSame($width,$result['width']); self::assertSame(intdiv($width,2),$result['height']);
            self::assertLessThanOrEqual(1048576,$result['bytes']); self::assertSame('image/jpeg',$result['mime']);
        }
    }
    public function test_square_carousel_is_reencoded_to_1024(): void
    {
        $i=new V2LineMediaInspector; $r=$i->resize($this->png(1600,1600),$this->directory.'/out',1024,true);
        self::assertSame(1024,$r['width']); self::assertSame(1024,$r['height']);
    }
    public function test_non_square_carousel_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new V2LineMediaInspector)->resize($this->png(),$this->directory.'/out',1024,true);
    }
    public function test_svg_and_corruption_and_oversize_fail_closed(): void
    {
        $i=new V2LineMediaInspector;
        foreach(['<svg xmlns="http://www.w3.org/2000/svg"/>',"\x89PNG\r\n\x1a\ninvalid",str_repeat('x',V2LineMediaInspector::IMAGE_BYTES+1)] as $bytes) {
            $path=$this->directory.'/bad'; file_put_contents($path,$bytes); clearstatcache(true,$path);
            try { $i->image($path); self::fail('Invalid image accepted'); } catch(\InvalidArgumentException $e) { self::assertSame('LINE_MEDIA_INVALID',$e->getMessage()); }
        }
    }
    public function test_giant_image_header_is_rejected_before_decoding(): void
    {
        $path=$this->png(); $bytes=file_get_contents($path);
        $bytes=substr_replace($bytes,pack('NN',100000,100000),16,8); file_put_contents($path,$bytes);
        $this->expectException(\InvalidArgumentException::class); (new V2LineMediaInspector)->image($path);
    }
    public function test_external_paths_and_symlinks_are_rejected(): void
    {
        $path=$this->png(); symlink($path,$this->directory.'/link');
        foreach(['https://example.invalid/asset','file://'.$path,$this->directory.'/link',$this->directory.'/../missing'] as $value) {
            try { (new V2LineMediaInspector)->image($value); self::fail('Nonlocal path accepted'); } catch(\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function test_real_mp4_and_preview_ratio_and_corrupt_mp4(): void
    {
        self::assertTrue(is_executable('/usr/bin/ffprobe'),'ffprobe is required; never skip media verification');
        $path=$this->directory.'/video.mp4';
        $p=new Process(['/usr/bin/ffmpeg','-v','error','-f','lavfi','-i','color=c=black:s=320x180:d=0.1','-threads','1','-c:v','libx264','-pix_fmt','yuv420p',$path],null,null,null,15); $p->mustRun();
        $i=new V2LineMediaInspector; $result=$i->video($path,$this->png());
        self::assertSame(320,$result['width']); self::assertSame(180,$result['height']);
        try { $i->video($path,$this->png(100,100,'square')); self::fail('Wrong preview ratio accepted'); } catch(\InvalidArgumentException) { self::assertTrue(true); }
        file_put_contents($path,"\x00\x00\x00\x20ftypisom".str_repeat('x',100));
        $this->expectException(\InvalidArgumentException::class); $i->video($path,$this->directory.'/input');
    }
}
